<?php

namespace App\Models;

use Database\Factories\AgentExecutionStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $enterprise_id
 * @property int $agent_execution_id
 * @property int $sequence
 * @property string $status
 * @property string $type
 * @property string|null $intent
 * @property array<string, mixed>|null $input_context
 * @property array<string, mixed>|null $output
 * @property array<array<string, mixed>>|null $capability_requests
 * @property string|null $failure_reason
 * @property string|null $failure_code
 * @property array<string, mixed>|null $failure_provenance
 * @property string|null $correlation_id
 * @property string $idempotency_key
 */
#[Fillable([
    'organization_id',
    'enterprise_id',
    'agent_execution_id',
    'workflow_stage_id',
    'sequence',
    'status',
    'type',
    'intent',
    'input_context',
    'output',
    'capability_requests',
    'failure_reason',
    'failure_code',
    'failure_provenance',
    'correlation_id',
    'idempotency_key',
    'started_at',
    'completed_at',
])]
class AgentExecutionStep extends Model
{
    /** @use HasFactory<AgentExecutionStepFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TYPE_REASONING = 'reasoning';

    public const TYPE_CAPABILITY = 'capability';

    public const TYPE_WORKFLOW = 'workflow';

    protected function casts(): array
    {
        return [
            'input_context' => 'array',
            'output' => 'array',
            'capability_requests' => 'array',
            'failure_provenance' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'agent_execution_id');
    }

    /** @return BelongsTo<WorkflowStage, $this> */
    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class);
    }

    public function start(): static
    {
        return $this->transitionTo(self::STATUS_RUNNING);
    }

    public function wait(?string $reason = null): static
    {
        $this->failure_reason = $reason;

        return $this->transitionTo(self::STATUS_WAITING);
    }

    public function complete(): static
    {
        return $this->transitionTo(self::STATUS_COMPLETED);
    }

    public function fail(?string $reason = null, ?string $code = null): static
    {
        $this->failure_reason = $reason;
        $this->failure_code = $code;

        return $this->transitionTo(self::STATUS_FAILED);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_WAITING, self::STATUS_COMPLETED, self::STATUS_FAILED],
            self::STATUS_WAITING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_COMPLETED, self::STATUS_FAILED => [],
            default => throw new LogicException('Agent execution step has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf(
                'Agent execution step cannot transition from [%s] to [%s].',
                $this->status,
                $status,
            ));
        }

        $this->status = $status;

        if ($status === self::STATUS_RUNNING) {
            $this->started_at ??= Carbon::now();
        }

        if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    protected static function booted(): void
    {
        static::saving(function (AgentExecutionStep $step): void {
            $execution = AgentExecution::query()->find($step->agent_execution_id);

            if ($execution === null) {
                throw new LogicException('Agent execution step requires a valid Agent execution.');
            }
            if ($step->type === self::TYPE_WORKFLOW && $step->workflow_stage_id === null) {
                throw new LogicException('Workflow execution steps require a workflow stage.');
            }

            if ($step->workflow_stage_id !== null) {
                $stage = WorkflowStage::query()->find($step->workflow_stage_id);
                if ($stage === null || $execution->workflow_id !== $stage->workflow_id) {
                    throw new LogicException('Agent execution workflow steps must reference their execution workflow.');
                }
            }

            if (
                $step->organization_id !== $execution->organization_id
                || $step->enterprise_id !== $execution->enterprise_id
            ) {
                throw new LogicException('Agent execution step must remain inside its Agent execution scope.');
            }

            if ($step->sequence < 1) {
                throw new LogicException('Agent execution step sequence must be positive.');
            }

            if ($step->status === self::STATUS_COMPLETED && $step->failure_reason !== null) {
                throw new LogicException('A completed Agent execution step cannot have a failure reason.');
            }

            if ($step->status === self::STATUS_FAILED && $step->failure_reason === null) {
                throw new LogicException('A failed Agent execution step must have a failure reason.');
            }
        });
    }
}