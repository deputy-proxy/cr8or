<?php

namespace App\Services;

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use Illuminate\Support\Facades\Gate;

final class KnowledgeRetrievalService
{
    public function __construct(
        private readonly KnowledgeRetrievalProvider $provider,
    ) {}

    public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
    {
        Gate::forUser($request->actor)->authorize('view', $request->enterprise);

        $result = $this->provider->retrieve($request);

        if ($result->correlationId !== ($request->correlationId ?? $result->correlationId)) {
            throw new \LogicException('Knowledge retrieval provider returned a mismatched correlation identifier.');
        }

        if ($result->candidateCount < count($result->items)) {
            throw new \LogicException('Knowledge retrieval candidate count cannot be lower than returned result count.');
        }

        return $result;
    }
}