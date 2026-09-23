<?php

namespace App\Models;

use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Carbon $requested_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $decided_at
 */
#[Fillable(['organization_id', 'enterprise_id', 'agent_assignment_id', 'agent_execution_id', 'actor_id', 'approver_id', 'capability', 'target_context', 'correlation_id', 'organization_name', 'enterprise_name', 'agent_slug', 'agent_runtime_class', 'actor_name', 'approver_name', 'status', 'requested_at', 'expires_at', 'decided_at', 'decision_reason'])]
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    private const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** @var list<string> */
    private const HISTORICAL_FIELDS = ['organization_id', 'enterprise_id', 'agent_assignment_id', 'agent_execution_id', 'actor_id', 'capability', 'target_context', 'correlation_id', 'organization_name', 'enterprise_name', 'agent_slug', 'agent_runtime_class', 'actor_name', 'requested_at', 'expires_at'];

    protected static function booted(): void
    {
        static::saving(function (ApprovalRequest $request): void {
            $request->validateState();
            $request->validateScope();

            if (! $request->exists) {
                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $request->{$field} = $request->getRawOriginal($field);
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
    public function agentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['target_context' => 'array', 'requested_at' => 'datetime', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function approve(User $approver, ?string $reason = null): static
    {
        $this->transitionTo(self::STATUS_APPROVED, $approver, $reason);

        return $this;
    }

    public function reject(User $approver, ?string $reason = null): static
    {
        $this->transitionTo(self::STATUS_REJECTED, $approver, $reason);

        return $this;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_APPROVED && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    private function transitionTo(string $status, User $approver, ?string $reason): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new LogicException('Only a pending approval request can be decided.');
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            throw new LogicException('An expired approval request cannot be decided.');
        }
        $this->status = $status;
        $this->approver_id = $approver->getKey();
        $this->approver_name = $approver->name;
        $this->decided_at = Carbon::now();
        $this->decision_reason = $reason;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid approval request status [{$this->status}].");
        }
        if ($this->status === self::STATUS_PENDING && ($this->approver_id !== null || $this->decided_at !== null)) {
            throw new LogicException('A pending approval request cannot have a decision.');
        }
        if ($this->status !== self::STATUS_PENDING && ($this->approver_id === null || $this->decided_at === null)) {
            throw new LogicException('A decided approval request must have an approver and decision timestamp.');
        }
    }

    private function validateScope(): void
    {
        if ($this->enterprise_id !== null) {
            $enterprise = Enterprise::query()->find($this->enterprise_id);
            if ($enterprise === null || $enterprise->organization_id !== $this->organization_id) {
                throw new LogicException('Approval request enterprise must belong to its organization.');
            }
        }

        $assignment = AgentAssignment::query()->find($this->agent_assignment_id);
        if ($assignment === null || $assignment->organization_id !== $this->organization_id || $assignment->enterprise_id !== $this->enterprise_id) {
            throw new LogicException('Approval request assignment must belong to its organization and enterprise scope.');
        }

        if ($this->agent_execution_id !== null) {
            $execution = AgentExecution::query()->find($this->agent_execution_id);
            if ($execution === null || $execution->organization_id !== $this->organization_id || $execution->enterprise_id !== $this->enterprise_id || $execution->agent_assignment_id !== $this->agent_assignment_id) {
                throw new LogicException('Approval request execution must belong to its organization, enterprise and assignment scope.');
            }
        }

        if ($this->approver_id !== null) {
            $approver = User::query()->find($this->approver_id);
            if ($approver === null || ! $approver->memberships()->where('organization_id', $this->organization_id)->exists()) {
                throw new LogicException('Approval request approver must belong to its organization.');
            }
        }
    }
}
