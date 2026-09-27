<?php

namespace App\Models;

use Database\Factories\AgentSemanticMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property array<string, mixed> $provenance
 * @property list<int>|null $conflict_memory_ids
 */
#[Fillable([
    'organization_id',
    'enterprise_id',
    'agent_descriptor_id',
    'statement',
    'confidence',
    'status',
    'conflict_memory_ids',
    'provenance',
])]
class AgentSemanticMemory extends Model
{
    /** @use HasFactory<AgentSemanticMemoryFactory> */
    use HasFactory;

    private ?int $versionActorId = null;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    private const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DISPUTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_ARCHIVED,
    ];

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'organization_id',
        'enterprise_id',
        'agent_descriptor_id',
    ];

    public function setVersionActor(?User $actor): static
    {
        $this->versionActorId = $actor?->getKey();

        return $this;
    }

    /** @return array<string, mixed> */
    public function provenanceMetadata(): array
    {
        $metadata = [
            'source_type' => $this->provenance['source_type'] ?? null,
            'source_id' => $this->provenance['source_id'] ?? null,
        ];

        if (($metadata['source_type'] ?? null) === AgentExecution::class && is_int($metadata['source_id'] ?? null)) {
            $execution = AgentExecution::query()->find($metadata['source_id']);

            if ($execution instanceof AgentExecution) {
                $metadata['historical_agent'] = [
                    'descriptor_id' => $execution->agent_descriptor_id,
                    'slug' => $execution->agent_slug,
                    'runtime_class' => $execution->agent_runtime_class,
                    'definition_version' => $execution->agent_definition_version,
                ];
            }
        }

        return $metadata;
    }

    protected static function booted(): void
    {
        static::saving(function (AgentSemanticMemory $memory): void {
            $memory->validateState();
            $memory->validateScope();

            if (! $memory->exists) {
                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $memory->{$field} = $memory->getRawOriginal($field);
            }
        });

        static::created(function (AgentSemanticMemory $memory): void {
            $memory->recordVersion('created');
        });

        static::updated(function (AgentSemanticMemory $memory): void {
            $memory->recordVersion('updated');
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

    /** @return HasMany<AgentSemanticMemoryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentSemanticMemoryVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'conflict_memory_ids' => 'array',
            'provenance' => 'array',
        ];
    }

    private function recordVersion(string $changeType): void
    {
        try {
            $this->versions()->create([
                'organization_id' => $this->organization_id,
                'enterprise_id' => $this->enterprise_id,
                'agent_descriptor_id' => $this->agent_descriptor_id,
                'statement' => $this->statement,
                'confidence' => $this->confidence,
                'status' => $this->status,
                'conflict_memory_ids' => $this->conflict_memory_ids,
                'provenance' => $this->provenance,
                'change_type' => $changeType,
                'changed_by_user_id' => $this->versionActorId,
                'recorded_at' => now(),
            ]);
        } finally {
            $this->versionActorId = null;
        }
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid Agent semantic memory status [{$this->status}].");
        }

        if ($this->confidence < 0 || $this->confidence > 1) {
            throw new LogicException('Agent semantic memory confidence must be between 0 and 1.');
        }

        if (trim((string) $this->statement) === '') {
            throw new LogicException('Agent semantic memory statement cannot be empty.');
        }

        if (
            ! isset($this->provenance['source_type'], $this->provenance['source_id'])
            || ! is_string($this->provenance['source_type'])
            || ! is_int($this->provenance['source_id'])
        ) {
            throw new LogicException('Agent semantic memory provenance must identify its authoritative source record.');
        }
    }

    private function validateScope(): void
    {
        $enterprise = Enterprise::query()->find($this->enterprise_id);

        if ($enterprise === null || $enterprise->organization_id !== $this->organization_id) {
            throw new LogicException('Agent semantic memory enterprise must belong to its organization.');
        }

        if ($this->provenance['source_type'] === AgentExecution::class) {
            /** @var AgentExecution|null $execution */
            $execution = AgentExecution::query()->find($this->provenance['source_id']);

            if (
                $execution === null
                || $execution->organization_id !== $this->organization_id
                || $execution->enterprise_id !== $this->enterprise_id
                || $execution->agent_descriptor_id !== $this->agent_descriptor_id
            ) {
                throw new LogicException('Agent semantic memory execution provenance must belong to its organization, enterprise and Agent scope.');
            }
        }
    }
}