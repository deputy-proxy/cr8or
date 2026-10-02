<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['workflow_id', 'workflow_version', 'organization_id', 'enterprise_id', 'actor_id', 'status', 'correlation_id', 'idempotency_key', 'current_stage_id', 'input', 'outputs', 'context', 'failure_reason', 'state_reason', 'started_at', 'completed_at'])]
/**
 * @property int $id
 * @property int $workflow_id
 * @property int $workflow_version
 * @property int $organization_id
 * @property int $enterprise_id
 * @property int $actor_id
 * @property string $status
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property int|null $current_stage_id
 * @property string|null $failure_reason
 * @property string|null $state_reason
 */
class WorkflowExecution extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowExecutionFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_FOR_INPUT = 'waiting_for_input';

    public const STATUS_WAITING_FOR_APPROVAL = 'waiting_for_approval';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return ['input' => 'array', 'outputs' => 'array', 'context' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<WorkflowStage, $this> */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function start(): static
    {
        return $this->transitionTo(self::STATUS_RUNNING);
    }

    public function waitForInput(?string $reason = null): static
    {
        $this->state_reason = $reason;

        return $this->transitionTo(self::STATUS_WAITING_FOR_INPUT);
    }

    public function waitForApproval(?string $reason = null): static
    {
        $this->state_reason = $reason;

        return $this->transitionTo(self::STATUS_WAITING_FOR_APPROVAL);
    }

    public function pause(?string $reason = null): static
    {
        $this->state_reason = $reason;

        return $this->transitionTo(self::STATUS_PAUSED);
    }

    public function fail(?string $reason = null): static
    {
        $this->failure_reason = $reason;

        return $this->transitionTo(self::STATUS_FAILED);
    }

    public function complete(): static
    {
        return $this->transitionTo(self::STATUS_COMPLETED);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_WAITING_FOR_INPUT, self::STATUS_WAITING_FOR_APPROVAL, self::STATUS_PAUSED, self::STATUS_FAILED, self::STATUS_COMPLETED],
            self::STATUS_WAITING_FOR_INPUT,self::STATUS_WAITING_FOR_APPROVAL => [self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_PAUSED],
            self::STATUS_PAUSED => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_FAILED,self::STATUS_COMPLETED => [],
            default => throw new LogicException('Workflow execution has no valid lifecycle state.'),
        };
        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Workflow execution cannot transition from [%s] to [%s].', $this->status, $status));
        }
        $this->status = $status;
        if ($status === self::STATUS_RUNNING) {
            $this->started_at ??= Carbon::now();
        }
        if (in_array($status, [self::STATUS_FAILED, self::STATUS_COMPLETED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    protected static function booted(): void
    {
        static::saving(function (WorkflowExecution $execution): void {
            if (! in_array($execution->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_WAITING_FOR_INPUT, self::STATUS_WAITING_FOR_APPROVAL, self::STATUS_PAUSED, self::STATUS_FAILED, self::STATUS_COMPLETED], true)) {
                throw new LogicException("Invalid workflow execution status [{$execution->status}].");
            }
            $workflow = Workflow::query()->find($execution->workflow_id);
            if ($workflow === null || $workflow->enterprise_id !== $execution->enterprise_id) {
                throw new LogicException('Workflow execution must belong to its Enterprise.');
            }
            if ((int) $execution->workflow_version !== (int) $workflow->version) {
                throw new LogicException('Workflow execution version must match its Workflow.');
            }
            $enterprise = Enterprise::query()->find($execution->enterprise_id);
            if ($enterprise === null || $enterprise->organization_id !== $execution->organization_id) {
                throw new LogicException('Workflow execution Enterprise must belong to its Organization.');
            }
            if ($execution->status === self::STATUS_FAILED && $execution->failure_reason === null) {
                throw new LogicException('A failed Workflow execution must have a failure reason.');
            }
        });
    }
}