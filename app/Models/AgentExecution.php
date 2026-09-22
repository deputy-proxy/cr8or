<?php

namespace App\Models;

use Database\Factories\AgentExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $actor_name
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $failure_reason
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
    'actor_name',
    'status',
    'requested_at',
    'started_at',
    'completed_at',
    'failure_reason',
])]
class AgentExecution extends Model
{
    /** @use HasFactory<AgentExecutionFactory> */
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    private const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_EXECUTING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function start(): static
    {
        $this->transitionTo(self::STATUS_EXECUTING);

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

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent execution status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_REQUESTED => [self::STATUS_EXECUTING, self::STATUS_FAILED],
            self::STATUS_EXECUTING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
            self::STATUS_SUCCEEDED, self::STATUS_FAILED => [],
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

        if ($status === self::STATUS_EXECUTING) {
            $this->started_at ??= Carbon::now();
        }

        if (in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent execution status [{$this->status}].");
        }

        if ($this->exists && $this->getRawOriginal('status') === self::STATUS_FAILED && $this->status === self::STATUS_SUCCEEDED) {
            throw new LogicException('A failed Agent execution cannot be represented as succeeded.');
        }

        if ($this->status === self::STATUS_SUCCEEDED && $this->failure_reason !== null) {
            throw new LogicException('A succeeded Agent execution cannot have a failure reason.');
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
    }
}
