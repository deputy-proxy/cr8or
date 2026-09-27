<?php

namespace App\Services;

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use Illuminate\Support\Facades\Gate;

final class KnowledgeRetrievalService
{
    private readonly KnowledgeRetrievalProvenanceService $provenance;

    private readonly KnowledgeRetrievalObservability $observability;

    public function __construct(
        private readonly KnowledgeRetrievalProvider $provider,
        ?KnowledgeRetrievalProvenanceService $provenance = null,
        ?KnowledgeRetrievalObservability $observability = null,
    ) {
        $this->provenance = $provenance ?? new KnowledgeRetrievalProvenanceService;
        $this->observability = $observability ?? new KnowledgeRetrievalObservability;
    }

    public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
    {
        Gate::forUser($request->actor)->authorize('view', $request->enterprise);

        $startedAt = microtime(true);
        $correlationId = $request->correlationId ?? 'knowledge-retrieval-'.bin2hex(random_bytes(8));

        try {
            $result = $this->provider->retrieve($request);

            if ($result->correlationId !== ($request->correlationId ?? $result->correlationId)) {
                throw new \LogicException('Knowledge retrieval provider returned a mismatched correlation identifier.');
            }

            if ($result->candidateCount < count($result->items)) {
                throw new \LogicException('Knowledge retrieval candidate count cannot be lower than returned result count.');
            }

            $normalized = $this->provenance->normalize($request->actor, $request->enterprise, $result);
            $this->observability->completed($request, $normalized, $startedAt);

            return $normalized;
        } catch (\Throwable $error) {
            $this->observability->failed($request, $correlationId, $startedAt, $error);

            throw $error;
        }
    }
}