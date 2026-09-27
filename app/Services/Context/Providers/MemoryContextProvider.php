<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentMemoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

final class MemoryContextProvider implements AgentContextProvider
{
    private const DEFAULT_CONTEXT_BUDGET = 20;

    private const MAX_CONTEXT_BUDGET = 50;

    private const DEFAULT_EPISODIC_LIMIT = 25;

    private const DEFAULT_SEMANTIC_LIMIT = 50;

    public function __construct(private readonly AgentMemoryService $memory) {}

    public function requirements(): array
    {
        return ['memory'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        if (! $assignment instanceof AgentAssignment) {
            throw new AuthorizationException('Agent memory context requires the current Agent Enterprise assignment.');
        }

        $assignment->loadMissing('agentDescriptor');

        if (! $assignment->enabled
            || $assignment->enterprise_id !== $enterprise->getKey()
            || $assignment->organization_id !== $enterprise->organization_id
            || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException('Agent memory context requires the current enabled Agent Enterprise assignment.');
        }

        $options = $this->options($targetContext);
        $budget = $options['budget'];
        $episodicBudget = (int) ceil($budget / 2);
        $episodicLimit = min($options['episodic_limit'], $episodicBudget);
        $semanticLimit = min($options['semantic_limit'], max(0, $budget - $episodicLimit));

        $memory = $this->memory->retrieve(
            actor: $user,
            enterprise: $enterprise,
            agent: $assignment->agentDescriptor,
            topic: $options['topic'],
            relevantAfter: $options['relevant_after'],
            episodicLimit: $episodicLimit,
            semanticStatus: $options['semantic_status'],
            semanticLimit: $semanticLimit,
        );

        return [new AgentContextSection(
            name: 'memory',
            data: [
                'episodic' => $memory['episodic']->map(function ($item): array {
                    $data = $item->toArray();
                    $data['provenance'] = $item->provenanceMetadata();

                    return $data;
                })->all(),
                'semantic' => $memory['semantic']->map(function ($item): array {
                    $data = $item->toArray();
                    $data['provenance'] = $item->provenanceMetadata();

                    return $data;
                })->all(),
                'selection' => [
                    'topic' => $options['topic'],
                    'relevant_after' => $options['relevant_after']?->toISOString(),
                    'budget' => $budget,
                ],
            ],
            source: self::class,
            scope: [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
                'agent_assignment_id' => $assignment->getKey(),
                'agent_descriptor_id' => $assignment->agent_descriptor_id,
            ],
            relevance: $options['topic'] === null && $options['relevant_after'] === null
                ? 'Bounded Agent persistent memory'
                : 'Bounded Agent memory matching the execution target context',
        )];
    }

    /** @param array<string, mixed> $targetContext
     * @return array{topic: ?string, relevant_after: ?Carbon, episodic_limit: int, semantic_status: ?string, semantic_limit: int, budget: int}
     */
    private function options(array $targetContext): array
    {
        $memory = $targetContext['memory'] ?? [];
        if (! is_array($memory)) {
            throw new AuthorizationException('Agent memory target context must be an array.');
        }

        $topic = $memory['topic'] ?? null;
        if ($topic !== null && ! is_string($topic)) {
            throw new AuthorizationException('Agent memory topic must be a string.');
        }

        $relevantAfter = $memory['relevant_after'] ?? null;
        if ($relevantAfter !== null && ! is_string($relevantAfter) && ! $relevantAfter instanceof \DateTimeInterface) {
            throw new AuthorizationException('Agent memory relevant_after must be a date string or DateTime instance.');
        }

        $budget = $this->boundedInteger($memory['budget'] ?? self::DEFAULT_CONTEXT_BUDGET, self::MAX_CONTEXT_BUDGET, 'budget');
        $episodicLimit = $this->boundedInteger($memory['episodic_limit'] ?? self::DEFAULT_EPISODIC_LIMIT, $budget, 'episodic_limit');
        $semanticLimit = $this->boundedInteger($memory['semantic_limit'] ?? self::DEFAULT_SEMANTIC_LIMIT, $budget, 'semantic_limit', true);
        $semanticStatus = $memory['semantic_status'] ?? null;

        if ($semanticStatus !== null && ! is_string($semanticStatus)) {
            throw new AuthorizationException('Agent memory semantic_status must be a string.');
        }

        return [
            'topic' => $topic === null ? null : trim($topic),
            'relevant_after' => $relevantAfter === null ? null : Carbon::parse($relevantAfter),
            'episodic_limit' => $episodicLimit,
            'semantic_status' => $semanticStatus,
            'semantic_limit' => $semanticLimit,
            'budget' => $budget,
        ];
    }

    private function boundedInteger(mixed $value, int $max, string $name, bool $allowZero = false): int
    {
        $minimum = $allowZero ? 0 : 1;

        if (! is_int($value) || $value < $minimum) {
            throw new AuthorizationException("Agent memory {$name} must be a positive integer.");
        }

        return min($value, $max);
    }
}