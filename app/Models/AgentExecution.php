<?php

namespace App\Models;

use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ExecutionErrorType;
use App\AI\Contracts\FailureCode;
use App\AI\Contracts\FailureProvenance;
use App\Enums\AgentExecutionMode;
use Database\Factories\AgentExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $enterprise_id
 * @property int|null $agent_descriptor_id
 * @property int|null $agent_assignment_id
 * @property int|null $actor_id
 * @property string|null $organization_name
 * @property string|null $enterprise_name
 * @property string|null $agent_slug
 * @property string|null $agent_runtime_class
 * @property string|null $agent_definition_version
 * @property string|null $actor_name
 * @property string $status
 * @property AgentExecutionMode $mode
 * @property Carbon $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $failure_reason
 * @property string|null $correlation_id
 * @property string|null $provider
 * @property string|null $external_execution_id
 * @property string|null $failure_code
 * @property string|null $failure_category
 * @property array<string, mixed>|null $failure_provenance
 * @property list<array<string, mixed>>|null $failure_history
 * @property int $retry_count
 * @property int $max_retries
 * @property int $max_steps
 * @property int $current_step
 * @property string|null $prompt
 * @property array<string, mixed>|null $target_context
 * @property list<string>|null $expert_slugs
 * @property array<string, mixed>|null $model_options
 * @property array<string, mixed>|null $runtime_policy
 * @property string|null $runtime_policy_version
 * @property array<string, mixed>|null $execution_context
 * @property array<string, mixed>|null $last_result
 * @property string|null $next_step
 * @property string|null $state_reason
 * @property string|null $idempotency_key
 */
#[Fillable([
    'organization_id',
    'enterprise_id',
    'agent_descriptor_id',
    'agent_assignment_id',
    'actor_id',
    'organization_name',
    'enterprise_name',
    'agent_slug',
    'agent_runtime_class',
    'agent_definition_version',
    'actor_name',
    'status',
    'mode',
    'requested_at',
    'started_at',
    'completed_at',
    'failure_reason',
    'correlation_id',
    'provider',
    'external_execution_id',
    'failure_code',
    'failure_category',
    'failure_provenance',
    'failure_history',
    'retry_count',
    'max_retries',
    'max_steps',
    'current_step',
    'prompt',
    'target_context',
    'expert_slugs',
    'model_options',
    'runtime_policy',
    'runtime_policy_version',
    'execution_context',
    'last_result',
    'next_step',
    'state_reason',
    'idempotency_key',
])]
class AgentExecution extends Model
{
    /** @use HasFactory<AgentExecutionFactory> */
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_REASONING = 'reasoning';

    public const STATUS_WAITING_FOR_INPUT = 'waiting_for_input';

    public const STATUS_WAITING_FOR_APPROVAL = 'waiting_for_approval';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_DELEGATED = 'delegated';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUCCEEDED = self::STATUS_COMPLETED;

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    private const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_REASONING,
        self::STATUS_WAITING_FOR_INPUT,
        self::STATUS_WAITING_FOR_APPROVAL,
        self::STATUS_EXECUTING,
        self::STATUS_DELEGATED,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'organization_id',
        'enterprise_id',
        'agent_descriptor_id',
        'agent_assignment_id',
        'actor_id',
        'organization_name',
        'enterprise_name',
        'agent_slug',
        'agent_runtime_class',
        'agent_definition_version',
        'actor_name',
        'requested_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (AgentExecution $execution): void {
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

    /** @return BelongsTo<AgentDescriptor, $this> */
    public function agentDescriptor(): BelongsTo
    {
        return $this->belongsTo(AgentDescriptor::class);
    }

    /** @return BelongsTo<AgentAssignment, $this> */
    public function agentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return HasMany<AgentDelegation, $this> */
    public function delegationsFrom(): HasMany
    {
        return $this->hasMany(AgentDelegation::class, 'parent_agent_execution_id');
    }

    /** @return HasMany<AgentExecutionStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentExecutionStep::class, 'agent_execution_id');
    }

    /** @return HasMany<AgentEpisodicMemory, $this> */
    public function episodicMemories(): HasMany
    {
        return $this->hasMany(AgentEpisodicMemory::class, 'execution_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'mode' => AgentExecutionMode::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'target_context' => 'array',
            'expert_slugs' => 'array',
            'model_options' => 'array',
            'runtime_policy' => 'array',
            'execution_context' => 'array',
            'last_result' => 'array',
            'failure_provenance' => 'array',
            'failure_history' => 'array',
        ];
    }

    public function assertMode(AgentExecutionMode $mode): void
    {
        if ($this->mode !== $mode) {
            throw new LogicException(sprintf('Agent execution mode [%s] does not match required mode [%s].', $this->mode->value, $mode->value));
        }
    }

    public function start(): static
    {
        $this->transitionTo(self::STATUS_REASONING);

        return $this;
    }

    public function beginReasoning(): static
    {
        return $this->transitionTo(self::STATUS_REASONING);
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

    public function markDelegated(?string $reason = null): static
    {
        $this->state_reason = $reason;

        return $this->transitionTo(self::STATUS_DELEGATED);
    }

    public function pause(?string $reason = null): static
    {
        $this->state_reason = $reason;

        return $this->transitionTo(self::STATUS_PAUSED);
    }

    public function complete(?string $reason = null): static
    {
        $this->state_reason = $reason;

        return $this->transitionTo(self::STATUS_COMPLETED);
    }

    public function succeed(): static
    {
        return $this->transitionTo(self::STATUS_SUCCEEDED);
    }

    public function cancel(?string $reason = null): static
    {
        $this->state_reason = $reason;

        if ($this->status !== self::STATUS_CANCELLED) {
            $error = new ExecutionError(
                type: ExecutionErrorType::Lifecycle,
                code: FailureCode::LIFECYCLE_CANCELLED,
                message: 'The Agent execution was cancelled.',
                retryable: false,
                correlationId: $this->correlation_id,
                diagnosticId: (string) \Illuminate\Support\Str::uuid(),
                provenance: new FailureProvenance(operation: 'AgentExecution.cancel'),
            );
            $this->failure_category = 'non_retryable';
            $this->failure_code = $error->code;
            $this->failure_provenance = $error->provenance->toArray();
            $this->recordFailure($error, 'agent.cancel');
        }

        return $this->transitionTo(self::STATUS_CANCELLED);
    }

    public function recordFailure(ExecutionError $error, ?string $source = null): static
    {
        $history = is_array($this->failure_history) ? $this->failure_history : [];
        $history[] = [
            'occurred_at' => now()->toIso8601String(),
            'source' => $source,
            'category' => $this->failure_category,
            'code' => $error->code,
            'message' => $error->message,
            'retryable' => $error->retryable,
            'retry_count' => $this->retry_count,
            'provenance' => $error->provenance->toArray(),
            'diagnostic_id' => $error->diagnosticId,
        ];
        $this->failure_history = $history;

        return $this;
    }

    public function fail(?string $reason = null): static
    {
        $this->failure_reason = $reason;
        $this->transitionTo(self::STATUS_FAILED);

        return $this;
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent execution status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_REQUESTED => [self::STATUS_REASONING, self::STATUS_FAILED, self::STATUS_CANCELLED],
            self::STATUS_REASONING => [
                self::STATUS_REASONING,
                self::STATUS_WAITING_FOR_INPUT,
                self::STATUS_WAITING_FOR_APPROVAL,
                self::STATUS_EXECUTING,
                self::STATUS_DELEGATED,
                self::STATUS_PAUSED,
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_WAITING_FOR_INPUT,
            self::STATUS_WAITING_FOR_APPROVAL,
            self::STATUS_DELEGATED,
            self::STATUS_PAUSED => [
                self::STATUS_REASONING,
                self::STATUS_EXECUTING,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_EXECUTING => [
                self::STATUS_REASONING,
                self::STATUS_WAITING_FOR_INPUT,
                self::STATUS_WAITING_FOR_APPROVAL,
                self::STATUS_DELEGATED,
                self::STATUS_PAUSED,
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED => [],
            default => throw new LogicException('Agent execution has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf(
                'Agent execution cannot transition from [%s] to [%s].',
                $this->status,
                $status,
            ));
        }

        $this->status = $status;

        if (in_array($status, [self::STATUS_REASONING, self::STATUS_EXECUTING], true)) {
            $this->started_at ??= Carbon::now();
        }

        if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent execution status [{$this->status}].");
        }

        if ($this->exists && in_array($this->getRawOriginal('status'), [self::STATUS_FAILED, self::STATUS_CANCELLED], true) && $this->status === self::STATUS_COMPLETED) {
            throw new LogicException('A failed Agent execution cannot be represented as succeeded.');
        }

        if ($this->status === self::STATUS_COMPLETED && $this->failure_reason !== null) {
            throw new LogicException('A completed Agent execution cannot have a failure reason.');
        }

        if ($this->status === self::STATUS_FAILED && $this->failure_reason === null) {
            throw new LogicException('A failed Agent execution must have a failure reason.');
        }
    }

    private function validateScope(): void
    {
        if ($this->enterprise_id !== null) {
            $enterprise = Enterprise::query()->find($this->enterprise_id);

            if ($enterprise === null || $enterprise->organization_id !== $this->organization_id) {
                throw new LogicException('Agent execution enterprise must belong to its organization.');
            }
        }

        if ($this->agent_assignment_id !== null) {
            $assignment = AgentAssignment::query()->find($this->agent_assignment_id);

            if ($assignment === null || $assignment->organization_id !== $this->organization_id || $assignment->enterprise_id !== $this->enterprise_id) {
                throw new LogicException('Agent execution assignment must belong to its organization and enterprise scope.');
            }
        }

        if ($this->enterprise_id !== null) {
            $enterprise = Enterprise::query()->find($this->enterprise_id);
            $identity = is_array($this->execution_context) && is_array($this->execution_context['enterprise_identity'] ?? null)
                ? $this->execution_context['enterprise_identity']
                : null;

            if ($identity !== null) {
                if ((int) ($identity['id'] ?? 0) !== (int) $enterprise->getKey() || ($identity['slug'] ?? null) !== $enterprise->slug) {
                    throw new LogicException('Agent execution enterprise identity does not match its enterprise scope.');
                }
            } else {
                $context = is_array($this->execution_context) ? $this->execution_context : [];
                $context['enterprise_identity'] = [
                    'id' => $enterprise->getKey(),
                    'slug' => $enterprise->slug,
                ];
                $this->execution_context = $context;
            }
        }
    }
}