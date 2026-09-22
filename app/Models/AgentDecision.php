<?php

namespace App\Models;

use Database\Factories\AgentDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 */
#[Fillable([
    'organization_id',
    'enterprise_id',
    'execution_id',
    'agent_descriptor_id',
    'actor_id',
    'organization_name',
    'enterprise_name',
    'agent_slug',
    'agent_runtime_class',
    'actor_name',
    'title',
    'summary',
    'rationale',
    'decided_at',
])]
class AgentDecision extends Model
{
    /** @use HasFactory<AgentDecisionFactory> */
    use HasFactory;

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'organization_id',
        'enterprise_id',
        'execution_id',
        'agent_descriptor_id',
        'actor_id',
        'organization_name',
        'enterprise_name',
        'agent_slug',
        'agent_runtime_class',
        'actor_name',
        'decided_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (AgentDecision $decision): void {
            $decision->validateScope();

            if (! $decision->exists) {
                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $decision->{$field} = $decision->getRawOriginal($field);
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

    /** @return BelongsTo<AgentExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'execution_id');
    }

    /** @return BelongsTo<AgentDescriptor, $this> */
    public function agentDescriptor(): BelongsTo
    {
        return $this->belongsTo(AgentDescriptor::class);
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
            'decided_at' => 'datetime',
        ];
    }

    private function validateScope(): void
    {
        if ($this->enterprise_id !== null) {
            $enterprise = Enterprise::query()->find($this->enterprise_id);

            if ($enterprise === null || $enterprise->organization_id !== $this->organization_id) {
                throw new \LogicException('Agent decision enterprise must belong to its organization.');
            }
        }

        if ($this->execution_id !== null) {
            $execution = AgentExecution::query()->find($this->execution_id);

            if ($execution === null || $execution->organization_id !== $this->organization_id || $execution->enterprise_id !== $this->enterprise_id) {
                throw new \LogicException('Agent decision execution must belong to its organization and enterprise scope.');
            }
        }
    }
}
