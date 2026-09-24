<?php

namespace App\Models;

use Database\Factories\AgentDelegationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'source_agent_assignment_id', 'target_agent_assignment_id', 'parent_agent_execution_id', 'target_agent_execution_id', 'actor_id', 'organization_name', 'enterprise_name', 'source_agent_slug', 'source_agent_runtime_class', 'target_agent_slug', 'target_agent_runtime_class', 'actor_name', 'capability', 'prompt', 'target_context', 'correlation_id', 'idempotency_key', 'attempts', 'status', 'requested_at', 'started_at', 'completed_at', 'failure_reason'])]
class AgentDelegation extends Model
{
    /** @use HasFactory<AgentDelegationFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    private const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    private const HISTORICAL_FIELDS = ['organization_id', 'enterprise_id', 'source_agent_assignment_id', 'target_agent_assignment_id', 'parent_agent_execution_id', 'actor_id', 'organization_name', 'enterprise_name', 'source_agent_slug', 'source_agent_runtime_class', 'target_agent_slug', 'target_agent_runtime_class', 'actor_name', 'capability', 'prompt', 'target_context', 'correlation_id', 'idempotency_key', 'requested_at'];

    protected static function booted(): void
    {
        static::saving(function (AgentDelegation $d): void {
            $d->validateState();
            $d->validateScope();
            if ($d->exists) {
                foreach (self::HISTORICAL_FIELDS as $f) {
                    $v = $d->getRawOriginal($f);
                    if ($f === 'target_context' && is_string($v)) {
                        do {
                            $decoded = json_decode($v, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                break;
                            } $v = $decoded;
                        } while (is_string($v));
                    } $d->{$f} = $v;
                }
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

    /** @return BelongsTo<AgentAssignment, $this> */
    public function sourceAgentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class, 'source_agent_assignment_id');
    }

    /** @return BelongsTo<AgentAssignment, $this> */
    public function targetAgentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class, 'target_agent_assignment_id');
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function targetAgentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'target_agent_execution_id');
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function parentAgentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'parent_agent_execution_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['target_context' => 'array', 'requested_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function start(): static
    {
        return $this->transitionTo(self::STATUS_RUNNING);
    }

    public function succeed(): static
    {
        return $this->transitionTo(self::STATUS_SUCCEEDED);
    }

    public function fail(?string $reason = null): static
    {
        $this->failure_reason = $reason;

        return $this->transitionTo(self::STATUS_FAILED);
    }

    public function retry(): static
    {
        if ($this->status !== self::STATUS_FAILED) {
            throw new LogicException('Only a failed Agent delegation can be retried.');
        }$this->status = self::STATUS_PENDING;
        $this->started_at = null;
        $this->completed_at = null;
        $this->failure_reason = null;

        return $this;
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent delegation status [{$status}].");
        }$allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],self::STATUS_SUCCEEDED,self::STATUS_FAILED => [],default => throw new LogicException('Agent delegation has no valid lifecycle state.')
        };
        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Agent delegation cannot transition from [%s] to [%s].', $this->status, $status));
        }$this->status = $status;
        if ($status === self::STATUS_RUNNING) {
            $this->started_at ??= Carbon::now();
            $this->attempts++;
        }if (in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent delegation status [{$this->status}].");
        }if ($this->exists && $this->getRawOriginal('status') === self::STATUS_FAILED && $this->status === self::STATUS_SUCCEEDED) {
            throw new LogicException('A failed Agent delegation cannot be represented as succeeded.');
        }if ($this->status === self::STATUS_SUCCEEDED && $this->failure_reason !== null) {
            throw new LogicException('A succeeded Agent delegation cannot have a failure reason.');
        }if ($this->status === self::STATUS_FAILED && $this->failure_reason === null) {
            throw new LogicException('A failed Agent delegation must have a failure reason.');
        }
    }

    private function validateScope(): void
    {
        $e = Enterprise::query()->find($this->enterprise_id);
        if ($e === null || $e->organization_id !== $this->organization_id) {
            throw new LogicException('Agent delegation enterprise must belong to its organization.');
        }foreach (['source_agent_assignment_id', 'target_agent_assignment_id'] as $f) {
            $a = AgentAssignment::query()->find($this->{$f});
            if ($a === null || $a->organization_id !== $this->organization_id || $a->enterprise_id !== $this->enterprise_id) {
                throw new LogicException('Agent delegation assignment must belong to its organization and Enterprise scope.');
            }
        }if ($this->parent_agent_execution_id !== null) {
            $x = AgentExecution::query()->find($this->parent_agent_execution_id);
            if ($x === null || $x->organization_id !== $this->organization_id || $x->enterprise_id !== $this->enterprise_id || $x->agent_assignment_id !== $this->source_agent_assignment_id) {
                throw new LogicException('Agent delegation parent execution must belong to its organization, Enterprise and source assignment scope.');
            }
        }
    }
}
