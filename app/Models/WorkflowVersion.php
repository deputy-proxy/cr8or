<?php

namespace App\Models;

use App\Services\WorkflowDefinitionValidator;
use Database\Factories\WorkflowVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['workflow_id', 'enterprise_id', 'version', 'status', 'name', 'purpose', 'execution_policy', 'completion_criteria', 'stage_definitions', 'idempotency_key', 'created_by', 'published_at', 'retired_at'])]
class WorkflowVersion extends Model
{
    /** @use HasFactory<WorkflowVersionFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected function casts(): array
    {
        return ['execution_policy' => 'array', 'completion_criteria' => 'array', 'stage_definitions' => 'array', 'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publish(): static
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new LogicException('Only draft WorkflowVersions can be published.');
        }

        app(WorkflowDefinitionValidator::class)->validateVersion($this);

        $this->status = self::STATUS_PUBLISHED;
        $this->published_at = Carbon::now();

        return $this;
    }

    public function retire(): static
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            throw new LogicException('Only published WorkflowVersions can be retired.');
        }
        $this->status = self::STATUS_RETIRED;
        $this->retired_at = Carbon::now();

        return $this;
    }

    protected static function booted(): void
    {
        static::saving(function (WorkflowVersion $version): void {
            if (! in_array($version->status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
                throw new LogicException("Invalid WorkflowVersion status [{$version->status}].");
            }
            $workflow = Workflow::query()->find($version->workflow_id);
            if ($workflow === null || (int) $workflow->enterprise_id !== (int) $version->enterprise_id) {
                throw new LogicException('WorkflowVersion enterprise scope must match its Workflow.');
            }
            if ((int) $version->version < 1) {
                throw new LogicException('WorkflowVersion number must be positive.');
            }
        });
        static::updating(function (WorkflowVersion $version): void {
            $originalStatus = $version->getOriginal('status');
            if (in_array($originalStatus, [self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
                if ($originalStatus === self::STATUS_PUBLISHED && $version->status === self::STATUS_RETIRED && $version->isDirty(['status', 'retired_at'])) {
                    return;
                }
                throw new LogicException('Published and retired WorkflowVersions are immutable.');
            }
        });
        static::deleting(function (WorkflowVersion $version): void {
            if (in_array($version->status, [self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
                throw new LogicException('Published and retired WorkflowVersions cannot be deleted.');
            }
        });
    }
}