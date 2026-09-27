<?php

namespace App\Services;

use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class AgentMemoryService
{
    public function __construct(
        private readonly AgentMemoryPolicy $policy,
        private readonly AgentEpisodicMemoryService $episodic,
        private readonly AgentSemanticMemoryService $semantic,
    ) {}

    /**
     * @return array{episodic: Collection<int, AgentEpisodicMemory>, semantic: Collection<int, AgentSemanticMemory>}
     */
    public function retrieve(
        User $actor,
        Enterprise $enterprise,
        AgentDescriptor $agent,
        ?string $topic = null,
        ?Carbon $relevantAfter = null,
        int $episodicLimit = 25,
        ?string $semanticStatus = null,
        int $semanticLimit = 50,
    ): array {
        $this->policy->authorizeRead($actor, $enterprise, $agent);

        return [
            'episodic' => $this->episodic->retrieve(
                $actor,
                $enterprise,
                $agent,
                $topic,
                $relevantAfter,
                $episodicLimit,
            ),
            'semantic' => $this->semantic->retrieve(
                $actor,
                $enterprise,
                $agent,
                $semanticStatus,
                $semanticLimit,
            ),
        ];
    }

    /** @param array<string, mixed> $provenance */
    public function rememberSemantic(
        User $actor,
        Enterprise $enterprise,
        AgentDescriptor $agent,
        string $statement,
        float $confidence,
        array $provenance,
    ): AgentSemanticMemory {
        $execution = $this->executionFromProvenance($provenance);
        $this->policy->authorizeRead($actor, $enterprise, $agent);
        $this->policy->authorizeSemanticWrite($actor, $execution);

        return $this->semantic->remember(
            $actor,
            $enterprise,
            $agent,
            $statement,
            $confidence,
            $provenance,
        );
    }

    /** @param array<string, mixed> $provenance */
    public function updateSemantic(
        User $actor,
        AgentSemanticMemory $memory,
        string $statement,
        float $confidence,
        array $provenance,
    ): AgentSemanticMemory {
        $execution = $this->executionFromProvenance($provenance);
        $this->policy->authorizeSemanticUpdate($actor, $memory, $execution);

        return $this->semantic->update(
            $actor,
            $memory,
            $statement,
            $confidence,
            $provenance,
        );
    }

    /** @param array<string, mixed> $provenance */
    public function recordEpisodic(
        User $actor,
        AgentExecution $execution,
        string $objective,
        string $action,
        string $result,
        string $outcome,
        ?string $topic = null,
        ?Carbon $occurredAt = null,
        array $provenance = [],
    ): AgentEpisodicMemory {
        $this->policy->authorizeEpisodicWrite($actor, $execution);

        return $this->episodic->recordMeaningfulEvent(
            $actor,
            $execution,
            $objective,
            $action,
            $result,
            $outcome,
            $topic,
            $occurredAt,
            $provenance,
        );
    }

    /** @param array<string, mixed> $provenance */
    private function executionFromProvenance(array $provenance): AgentExecution
    {
        if (
            ($provenance['source_type'] ?? null) !== AgentExecution::class
            || ! is_int($provenance['source_id'] ?? null)
        ) {
            throw new \InvalidArgumentException(
                'Agent memory writes require Agent execution provenance.',
            );
        }

        $execution = AgentExecution::query()->find($provenance['source_id']);

        if (! $execution instanceof AgentExecution) {
            throw new \InvalidArgumentException(
                'Agent memory provenance references an unknown Agent execution.',
            );
        }

        return $execution;
    }
}
