<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\KnowledgeRetrievalRequest;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeRetrievalService;

final class RetrieveKnowledge implements Operation
{
    public function __construct(private readonly KnowledgeRetrievalService $retrieval) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->retrieval->retrieve(new KnowledgeRetrievalRequest(
            actor: $actor,
            enterprise: $enterprise,
            query: $input['query'] ?? null,
            objective: $input['objective'] ?? null,
            mode: (string) ($input['mode'] ?? 'hybrid'),
            limits: ['limit' => (int) ($input['limit'] ?? 20)],
            relevance: array_key_exists('minimum_relevance', $input) && $input['minimum_relevance'] !== null
                ? ['minimum_relevance' => $input['minimum_relevance']]
                : [],
            correlationId: $input['correlation_id'] ?? null,
        ))->toArray();
    }
}