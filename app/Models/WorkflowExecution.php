<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['workflow_id', 'workflow_version_id', 'workflow_version', 'enterprise_id', 'actor_id', 'status', 'correlation_id', 'idempotency_key', 'current_stage_id', 'current_stage_key', 'continuation_token', 'input', 'outputs', 'context', 'failure_reason', 'state_reason', 'started_at', 'completed_at'])]
/**
 * @property int $id
 * @property int $workflow_id
 * @property int|null $workflow_version_id
 * @property int $workflow_version
 * @property int $enterprise_id
 * @property int $actor_id
 * @property string $status
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property int|null $current_stage_id
 * @property string|null $current_stage_key
 * @property string $continuation_token
 * @property string|null $failure_reason
 * @property string|null $state_reason
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $outputs
 * @property array<string, mixed>|null $context
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

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    /** @return BelongsTo<WorkflowStage, $this> */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
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

    public function retry(): static
    {
        if ($this->status !== self::STATUS_FAILED) {
            throw new LogicException('Only failed WorkflowExecutions can be retried.');
        }
        $this->failure_reason = null;
        $this->completed_at = null;
        $this->continuation_token = (string) str()->uuid();

        return $this->transitionTo(self::STATUS_RUNNING);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_WAITING_FOR_INPUT, self::STATUS_WAITING_FOR_APPROVAL, self::STATUS_PAUSED, self::STATUS_FAILED, self::STATUS_COMPLETED],
            self::STATUS_WAITING_FOR_INPUT,self::STATUS_WAITING_FOR_APPROVAL => [self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_PAUSED],
            self::STATUS_PAUSED => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_FAILED => [self::STATUS_RUNNING],
            self::STATUS_COMPLETED => [],
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
            $enterprise = $execution->enterprise;
            if ($workflow === null || $enterprise === null || ! $workflow->isAvailableForEnterprise($enterprise)) {
                throw new LogicException('Workflow execution must use a Workflow available to its Enterprise.');
            }
            $version = WorkflowVersion::query()->find($execution->workflow_version_id);
            if ($version === null || $version->workflow_id !== $workflow->getKey() || (int) $version->enterprise_id !== (int) $workflow->enterprise_id) {
                throw new LogicException('Workflow execution must reference an exact WorkflowVersion for its Workflow scope.');
            }
            if ($version->status !== WorkflowVersion::STATUS_PUBLISHED && $execution->status === self::STATUS_PENDING) {
                throw new LogicException('Workflow execution can only start from a published WorkflowVersion.');
            }
            if ((int) $execution->workflow_version !== (int) $version->version) {
                throw new LogicException('Workflow execution version snapshot must match its WorkflowVersion.');
            }
            if ($execution->status === self::STATUS_FAILED && $execution->failure_reason === null) {
                throw new LogicException('A failed Workflow execution must have a failure reason.');
            }
        });
    }
}