<?php

namespace App\Services;

use App\Models\AgentDescriptor;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class AgentSemanticMemoryService
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    /**
     * Store one durable Agent-learned statement inside one Enterprise scope.
     *
     * @param  array<string, mixed>  $provenance
     */
    public function remember(
        User $actor,
        Enterprise $enterprise,
        AgentDescriptor $agent,
        string $statement,
        float $confidence,
        array $provenance,
        string $status = AgentSemanticMemory::STATUS_ACTIVE,
    ): AgentSemanticMemory {
        $this->authorizeAgentScope($actor, $enterprise, $agent);
        $this->validateContent($statement, $confidence, $provenance);

        return AgentSemanticMemory::query()->create([
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'agent_descriptor_id' => $agent->getKey(),
            'statement' => trim($statement),
            'confidence' => $confidence,
            'status' => $status,
            'conflict_memory_ids' => null,
            'provenance' => $provenance,
        ]);
    }

    /**
     * Update the explicit semantic state while preserving the previous version.
     * A changed statement is an explicit replacement, not a silent overwrite.
     *
     * @param  array<string, mixed>  $provenance
     */
    public function update(
        User $actor,
        AgentSemanticMemory $memory,
        string $statement,
        float $confidence,
        array $provenance,
        string $status = AgentSemanticMemory::STATUS_ACTIVE,
    ): AgentSemanticMemory {
        Gate::forUser($actor)->authorize('view', $memory->enterprise);
        $this->validateContent($statement, $confidence, $provenance);

        $memory->statement = trim($statement);
        $memory->confidence = $confidence;
        $memory->status = $status;
        $memory->provenance = $provenance;
        $memory->save();

        return $memory->refresh();
    }

    /**
     * Mark two scoped memories as an explicit contradiction while preserving both.
     */
    public function recordConflict(
        User $actor,
        AgentSemanticMemory $memory,
        AgentSemanticMemory $conflictingMemory,
    ): void {
        if ($memory->getKey() === $conflictingMemory->getKey()) {
            throw new InvalidArgumentException('An Agent semantic memory cannot conflict with itself.');
        }

        Gate::forUser($actor)->authorize('view', $memory->enterprise);
        Gate::forUser($actor)->authorize('view', $conflictingMemory->enterprise);

        if (
            $memory->organization_id !== $conflictingMemory->organization_id
            || $memory->enterprise_id !== $conflictingMemory->enterprise_id
            || $memory->agent_descriptor_id !== $conflictingMemory->agent_descriptor_id
        ) {
            throw new AuthorizationException('Agent semantic memory conflicts must remain within one Agent Enterprise scope.');
        }

        DB::transaction(function () use ($memory, $conflictingMemory): void {
            /** @var list<int> $memoryConflictIds */
            $memoryConflictIds = $memory->conflict_memory_ids ?? [];
            $memory->status = AgentSemanticMemory::STATUS_DISPUTED;
            $memory->conflict_memory_ids = array_values(array_unique([
                ...$memoryConflictIds,
                (int) $conflictingMemory->getKey(),
            ]));
            $memory->save();

            /** @var list<int> $conflictingMemoryConflictIds */
            $conflictingMemoryConflictIds = $conflictingMemory->conflict_memory_ids ?? [];
            $conflictingMemory->status = AgentSemanticMemory::STATUS_DISPUTED;
            $conflictingMemory->conflict_memory_ids = array_values(array_unique([
                ...$conflictingMemoryConflictIds,
                (int) $memory->getKey(),
            ]));
            $conflictingMemory->save();
        });
    }

    /**
     * Retrieve bounded semantic memory for one authorized Enterprise and Agent.
     *
     * @return Collection<int, AgentSemanticMemory>
     */
    public function retrieve(
        User $actor,
        Enterprise $enterprise,
        AgentDescriptor $agent,
        ?string $status = null,
        int $limit = self::DEFAULT_LIMIT,
    ): Collection {
        $this->authorizeAgentScope($actor, $enterprise, $agent);

        if ($status !== null && ! in_array($status, [
            AgentSemanticMemory::STATUS_ACTIVE,
            AgentSemanticMemory::STATUS_DISPUTED,
            AgentSemanticMemory::STATUS_SUPERSEDED,
            AgentSemanticMemory::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException("Invalid Agent semantic memory status [{$status}].");
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        return AgentSemanticMemory::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->where('agent_descriptor_id', $agent->getKey())
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @param array<string, mixed> $provenance */
    private function validateContent(string $statement, float $confidence, array $provenance): void
    {
        if (trim($statement) === '') {
            throw new InvalidArgumentException('Agent semantic memory statement cannot be empty.');
        }

        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Agent semantic memory confidence must be between 0 and 1.');
        }

        if (
            ! isset($provenance['source_type'], $provenance['source_id'])
            || ! is_string($provenance['source_type'])
            || ! is_int($provenance['source_id'])
        ) {
            throw new InvalidArgumentException('Agent semantic memory provenance must identify its authoritative source record.');
        }
    }

    private function authorizeAgentScope(User $actor, Enterprise $enterprise, AgentDescriptor $agent): void
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if (! $agent->exists) {
            throw new AuthorizationException('Agent semantic memory requires an existing Agent descriptor.');
        }
    }
}
