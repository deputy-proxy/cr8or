<?php

namespace App\Models;

use Database\Factories\AgentAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'agent_descriptor_id',
    'organization_id',
    'enterprise_id',
    'enabled',
    'status',
    'objective',
    'requirements',
    'context',
    'correlation_id',
    'idempotency_key',
    'started_at',
    'completed_at',
])]
class AgentAssignment extends Model
{
    /** @use HasFactory<AgentAssignmentFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_READY,
        self::STATUS_RUNNING,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** @return array<string, list<string>> */
    public static function transitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_READY, self::STATUS_CANCELLED],
            self::STATUS_READY => [self::STATUS_RUNNING, self::STATUS_CANCELLED],
            self::STATUS_RUNNING => [self::STATUS_PAUSED, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED],
            self::STATUS_PAUSED => [self::STATUS_RUNNING, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [],
            self::STATUS_FAILED => [self::STATUS_READY, self::STATUS_CANCELLED],
            self::STATUS_CANCELLED => [],
        ];
    }

    /** @return BelongsTo<AgentDescriptor, $this> */
    public function agentDescriptor(): BelongsTo
    {
        return $this->belongsTo(AgentDescriptor::class);
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

    /** @return HasMany<Assignment, $this> */
    public function workAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'requirements' => 'array',
            'context' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AgentAssignment $assignment): void {
            if (! in_array($assignment->status, self::STATUSES, true)) {
                throw new LogicException("Invalid Agent Assignment status [{$assignment->status}].");
            }

            if ($assignment->enterprise_id !== null) {
                $enterprise = Enterprise::query()->find($assignment->enterprise_id);

                if ($enterprise === null || $enterprise->organization_id !== $assignment->organization_id) {
                    throw new LogicException('Agent Assignment Enterprise must belong to its organization.');
                }

                $agent = AgentDescriptor::query()->find($assignment->agent_descriptor_id);

                if ($agent === null || ! $agent->enabled) {
                    throw new LogicException('Agent Assignment requires an enabled Agent descriptor.');
                }
            }

            if (in_array($assignment->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
                $assignment->enabled = false;
            }
        });
    }
}
