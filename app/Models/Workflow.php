<?php

namespace App\Models;

use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'project_id', 'task_id', 'work_item_id', 'name', 'status'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    private const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    protected static function booted(): void
    {
        static::saving(function (Workflow $workflow): void {
            $workflow->validateState();
            $workflow->validateScope();
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /** @return HasMany<Job, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid workflow status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
            self::STATUS_SUCCEEDED, self::STATUS_FAILED => [],
            default => throw new LogicException('Workflow has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf(
                'Workflow cannot transition from [%s] to [%s].',
                $this->status,
                $status,
            ));
        }

        $this->status = $status;

        return $this;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid workflow status [{$this->status}].");
        }
    }

    private function validateScope(): void
    {
        foreach ([
            'project_id' => Project::class,
            'task_id' => Task::class,
            'work_item_id' => WorkItem::class,
        ] as $field => $model) {
            $id = $this->{$field};

            if ($id === null) {
                continue;
            }

            $record = $model::query()->find($id);

            if ($record === null || $record->enterprise_id !== $this->enterprise_id) {
                throw new LogicException("Workflow {$field} must belong to its enterprise.");
            }
        }
    }
}
