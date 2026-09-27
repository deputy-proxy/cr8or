<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Data\KnowledgeRetrievalRequest;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeRetrievalService;
use InvalidArgumentException;

final class RetrievedKnowledgeContextProvider implements AgentContextProvider
{
    private const DEFAULT_LIMIT = 5;

    private const DEFAULT_BUDGET = 1200;

    private const MAX_LIMIT = 50;

    private const MAX_BUDGET = 8000;

    public function __construct(
        private readonly KnowledgeRetrievalService $retrieval,
    ) {}

    public function requirements(): array
    {
        return ['retrieved_knowledge'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        $options = $targetContext['retrieved_knowledge'] ?? [];

        if (! is_array($options)) {
            throw new InvalidArgumentException('Retrieved Knowledge context options must be an array.');
        }

        $query = isset($options['query']) ? trim((string) $options['query']) : '';
        $objective = isset($options['objective']) ? trim((string) $options['objective']) : '';

        if ($query === '' && $objective === '') {
            throw new InvalidArgumentException('Retrieved Knowledge context requires a query or objective.');
        }

        $limit = $this->boundedInt($options['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, 'limit');
        $budget = $this->boundedInt($options['budget'] ?? self::DEFAULT_BUDGET, 1, self::MAX_BUDGET, 'budget');
        $mode = trim((string) ($options['mode'] ?? 'hybrid'));

        $result = $this->retrieval->retrieve(new KnowledgeRetrievalRequest(
            actor: $user,
            enterprise: $enterprise,
            query: $query !== '' ? $query : null,
            objective: $objective !== '' ? $objective : null,
            mode: $mode,
            limits: ['limit' => $limit],
            relevance: isset($options['minimum_relevance']) ? ['minimum_relevance' => $options['minimum_relevance']] : [],
            correlationId: isset($options['correlation_id']) ? (string) $options['correlation_id'] : null,
        ));

        $items = [];
        $used = 0;

        foreach ($result->items as $item) {
            $data = $item->toArray();
            $estimatedTokens = $this->estimateTokens($data);

            if ($estimatedTokens > $budget) {
                continue;
            }

            if ($used + $estimatedTokens > $budget) {
                break;
            }

            $items[] = $data;
            $used += $estimatedTokens;
        }

        return [new AgentContextSection(
            name: 'retrieved_knowledge',
            data: [
                'query' => $query !== '' ? $query : null,
                'objective' => $objective !== '' ? $objective : null,
                'items' => $items,
                'selection' => [
                    'mode' => $mode,
                    'requested_limit' => $limit,
                    'returned_count' => count($items),
                    'candidate_count' => $result->candidateCount,
                    'budget' => $budget,
                    'estimated_tokens' => $used,
                    'truncated' => count($items) < count($result->items),
                ],
            ],
            source: self::class,
            scope: [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
            ],
            relevance: 'Governed Knowledge retrieval for current Agent execution',
        )];
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum, string $name): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Retrieved Knowledge {$name} must be an integer between {$minimum} and {$maximum}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function estimateTokens(array $data): int
    {
        return max(1, (int) ceil(strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) / 4));
    }
}