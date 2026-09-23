<?php

namespace App\Models;

use Database\Factories\ExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable([
    'workflow_job_id',
    'organization_id',
    'enterprise_id',
    'project_id',
    'task_id',
    'work_item_id',
    'organization_name',
    'enterprise_name',
    'project_name',
    'task_name',
    'work_item_name',
    'status',
    'started_at',
    'completed_at',
    'failure_reason',
])]
class Execution extends Model
{
    /** @use HasFactory<ExecutionFactory> */
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

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'organization_id',
        'enterprise_id',
        'project_id',
        'task_id',
        'work_item_id',
        'organization_name',
        'enterprise_name',
        'project_name',
        'task_name',
        'work_item_name',
    ];

    protected static function booted(): void
    {
        static::saving(function (Execution $execution): void {
            $execution->hydrateOrigin();
            $execution->validateState();
            $execution->validateScope();

            if (! $execution->exists) {
                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $execution->{$field} = $execution->getRawOriginal($field);
            }
        });
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'workflow_job_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    public function start(): static
    {
        $this->transitionTo(self::STATUS_RUNNING);

        return $this;
    }

    public function succeed(): static
    {
        $this->transitionTo(self::STATUS_SUCCEEDED);

        return $this;
    }

    public function fail(?string $reason = null): static
    {
        $this->failure_reason = $reason;
        $this->transitionTo(self::STATUS_FAILED);

        return $this;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid execution status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
            self::STATUS_SUCCEEDED, self::STATUS_FAILED => [],
            default => throw new LogicException('Execution has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf(
                'Execution cannot transition from [%s] to [%s].',
                $this->status,
                $status,
            ));
        }

        $this->status = $status;

        if ($status === self::STATUS_RUNNING) {
            $this->started_at ??= Carbon::now();
        }

        if (in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    private function hydrateOrigin(): void
    {
        if ($this->exists) {
            return;
        }

        $workflow = $this->job->workflow;

        $enterprise = $workflow->enterprise;
        $this->organization_id = $enterprise->organization_id;
        $this->enterprise_id = $enterprise->id;
        $this->project_id = $workflow->project_id;
        $this->task_id = $workflow->task_id;
        $this->work_item_id = $workflow->work_item_id;
        $this->organization_name = $enterprise->organization->name;
        $this->enterprise_name = $enterprise->name;
        $this->project_name = $workflow->project?->name;
        $this->task_name = $workflow->task?->name;
        $this->work_item_name = $workflow->workItem?->name;
    }

    private function validateScope(): void
    {
        $enterprise = $this->enterprise_id === null
            ? null
            : Enterprise::query()->find($this->enterprise_id);

        if ($enterprise === null || $enterprise->organization_id !== $this->organization_id) {
            throw new LogicException('Execution enterprise must belong to its organization.');
        }

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
                throw new LogicException("Execution {$field} must belong to its enterprise.");
            }
        }
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid execution status [{$this->status}].");
        }

        if ($this->status === self::STATUS_SUCCEEDED && $this->failure_reason !== null) {
            throw new LogicException('A succeeded execution cannot have a failure reason.');
        }

        if ($this->status === self::STATUS_FAILED && $this->failure_reason === null) {
            throw new LogicException('A failed execution must have a failure reason.');
        }

        if ($this->exists && $this->getRawOriginal('status') === self::STATUS_FAILED && $this->status === self::STATUS_SUCCEEDED) {
            throw new LogicException('A failed execution cannot be represented as succeeded.');
        }
    }
}
