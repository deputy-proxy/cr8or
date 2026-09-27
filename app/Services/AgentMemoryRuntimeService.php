<?php

namespace App\Services;

use App\AI\Data\ModelResult;
use App\Events\MemoryRecorded;
use App\Models\AgentDecision;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\User;

final class AgentMemoryRuntimeService
{
    private const SEMANTIC_CONFIDENCE_THRESHOLD = 0.75;

    public function __construct(
        private readonly AgentMemoryService $memory,
        private readonly AgentMemoryPolicy $policy,
    ) {}

    /**
     * Consolidate explicitly requested memory candidates from one terminal execution.
     *
     * Candidate memory is model output, not authoritative state. Only candidates
     * satisfying the memory policy are persisted.
     */
    public function consolidate(
        User $actor,
        AgentExecution $execution,
        ?ModelResult $result,
        ?AgentDecision $decision,
    ): void {
        if (! in_array($execution->status, [
            AgentExecution::STATUS_COMPLETED,
            AgentExecution::STATUS_FAILED,
        ], true)) {
            return;
        }

        $structured = $result !== null
            ? $result->structured
            : (is_array($execution->last_result) ? ($execution->last_result['structured'] ?? null) : null);

        if (! is_array($structured)) {
            $structured = [];
        }

        $memory = $structured['memory'] ?? [];
        if (! is_array($memory)) {
            return;
        }

        foreach ($this->candidates($memory['episodic'] ?? null) as $candidate) {
            if (! $this->policy->allowsEpisodicCandidate($execution, $candidate)) {
                continue;
            }

            $memoryRecord = $this->memory->recordEpisodic(
                actor: $actor,
                execution: $execution,
                objective: $candidate['objective'],
                action: $candidate['action'],
                result: $candidate['result'],
                outcome: $candidate['outcome'],
                topic: $candidate['topic'] ?? null,
                provenance: $this->provenance($execution, $decision, $candidate, 'episodic'),
            );
            app(AgentExecutionEventService::class)->dispatch(MemoryRecorded::class, $execution, provenance: [
                'memory_type' => 'episodic',
                'memory_id' => $memoryRecord->getKey(),
            ], data: [
                'memory_type' => 'episodic',
            ]);
        }

        if ($execution->status !== AgentExecution::STATUS_COMPLETED) {
            return;
        }

        foreach ($this->candidates($memory['semantic'] ?? null) as $candidate) {
            if (! $this->policy->allowsSemanticCandidate($execution, $candidate, self::SEMANTIC_CONFIDENCE_THRESHOLD)) {
                continue;
            }

            $agent = $execution->agentDescriptor;
            $enterprise = $execution->enterprise;

            if (! $agent || ! $enterprise) {
                continue;
            }

            $provenance = $this->provenance($execution, $decision, $candidate, 'semantic');
            $supersedesId = $candidate['supersedes_memory_id'] ?? null;

            if (is_int($supersedesId)) {
                $existing = AgentSemanticMemory::query()
                    ->whereKey($supersedesId)
                    ->where('organization_id', $execution->organization_id)
                    ->where('enterprise_id', $execution->enterprise_id)
                    ->where('agent_descriptor_id', $execution->agent_descriptor_id)
                    ->first();

                if ($existing instanceof AgentSemanticMemory) {
                    $existing->status = AgentSemanticMemory::STATUS_SUPERSEDED;
                    $existing->setVersionActor($actor);
                    $existing->save();

                    $memoryRecord = $this->memory->rememberSemantic(
                        actor: $actor,
                        enterprise: $enterprise,
                        agent: $agent,
                        statement: $candidate['statement'],
                        confidence: $candidate['confidence'],
                        provenance: $provenance,
                    );
                    app(AgentExecutionEventService::class)->dispatch(MemoryRecorded::class, $execution, provenance: [
                        'memory_type' => 'semantic',
                        'memory_id' => $memoryRecord->getKey(),
                    ], data: [
                        'memory_type' => 'semantic',
                    ]);

                    continue;
                }
            }

            $duplicate = AgentSemanticMemory::query()
                ->where('organization_id', $execution->organization_id)
                ->where('enterprise_id', $execution->enterprise_id)
                ->where('agent_descriptor_id', $execution->agent_descriptor_id)
                ->where('statement', $candidate['statement'])
                ->where('status', AgentSemanticMemory::STATUS_ACTIVE)
                ->exists();

            if ($duplicate) {
                continue;
            }

            $memoryRecord = $this->memory->rememberSemantic(
                actor: $actor,
                enterprise: $enterprise,
                agent: $agent,
                statement: $candidate['statement'],
                confidence: $candidate['confidence'],
                provenance: $provenance,
            );
            app(AgentExecutionEventService::class)->dispatch(MemoryRecorded::class, $execution, provenance: [
                'memory_type' => 'semantic',
                'memory_id' => $memoryRecord->getKey(),
            ], data: [
                'memory_type' => 'semantic',
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function candidates(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $candidate): bool => is_array($candidate),
        ));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function provenance(
        AgentExecution $execution,
        ?AgentDecision $decision,
        array $candidate,
        string $type,
    ): array {
        return [
            'source_type' => AgentExecution::class,
            'source_id' => (int) $execution->getKey(),
            'memory_type' => $type,
            'source_step' => isset($candidate['source_step']) && is_int($candidate['source_step'])
                ? $candidate['source_step']
                : $execution->current_step,
            'decision_id' => $decision?->getKey(),
        ];
    }
}