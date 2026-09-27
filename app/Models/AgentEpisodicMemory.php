<?php

namespace App\Models;

use Database\Factories\AgentEpisodicMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property array<string, mixed> $provenance
 */
#[Fillable([
    'organization_id',
    'enterprise_id',
    'agent_descriptor_id',
    'execution_id',
    'topic',
    'objective',
    'action',
    'result',
    'outcome',
    'occurred_at',
    'provenance',
])]
class AgentEpisodicMemory extends Model
{
    /** @use HasFactory<AgentEpisodicMemoryFactory> */
    use HasFactory;

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'organization_id',
        'enterprise_id',
        'agent_descriptor_id',
        'execution_id',
        'topic',
        'objective',
        'action',
        'result',
        'outcome',
        'occurred_at',
        'provenance',
    ];

    protected static function booted(): void
    {
        static::saving(function (AgentEpisodicMemory $memory): void {
            $memory->validateScope();

            if (! $memory->exists) {
                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $memory->{$field} = $memory->getRawOriginal($field);
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

    /** @return BelongsTo<AgentExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'execution_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'provenance' => 'array',
        ];
    }

    private function validateScope(): void
    {
        $enterprise = Enterprise::query()->find($this->enterprise_id);

        if ($enterprise === null || $enterprise->organization_id !== $this->organization_id) {
            throw new LogicException('Agent episodic memory enterprise must belong to its organization.');
        }

        $execution = AgentExecution::query()->find($this->execution_id);

        if (
            $execution === null
            || $execution->organization_id !== $this->organization_id
            || $execution->enterprise_id !== $this->enterprise_id
            || $execution->agent_descriptor_id !== $this->agent_descriptor_id
        ) {
            throw new LogicException('Agent episodic memory execution must belong to its organization, enterprise and Agent scope.');
        }

        if (
            ! isset($this->provenance['source_type'], $this->provenance['source_id'])
            || ! is_string($this->provenance['source_type'])
            || ! is_int($this->provenance['source_id'])
        ) {
            throw new LogicException('Agent episodic memory provenance must identify its authoritative source record.');
        }

        if ($this->provenance['source_type'] !== AgentExecution::class || $this->provenance['source_id'] !== $this->execution_id) {
            throw new LogicException('Agent episodic memory provenance must reference its source Agent execution.');
        }
    }
}