<?php

namespace App\Services;

use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use Illuminate\Support\Facades\Log;
use Throwable;

final class KnowledgeRetrievalObservability
{
    public function completed(KnowledgeRetrievalRequest $request, KnowledgeRetrievalResult $result, float $startedAt): void
    {
        Log::info('CR8OR Knowledge retrieval completed.', [
            'operation' => 'knowledge.retrieve',
            'correlation_id' => $result->correlationId,
            'enterprise_id' => $request->enterprise->getKey(),
            'mode' => $request->mode,
            'candidate_count' => $result->candidateCount,
            'result_count' => count($result->items),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'provider_metadata' => $this->safeMetadata($result->metadata),
        ]);
    }

    public function failed(KnowledgeRetrievalRequest $request, string $correlationId, float $startedAt, Throwable $error): void
    {
        Log::warning('CR8OR Knowledge retrieval failed.', [
            'operation' => 'knowledge.retrieve',
            'correlation_id' => $correlationId,
            'enterprise_id' => $request->enterprise->getKey(),
            'mode' => $request->mode,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_class' => $error::class,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip([
            'mode',
            'weights',
            'embedding_version',
            'term_count',
            'lexical_candidates',
            'semantic_candidates',
            'provenance_normalized',
        ]));
    }
}