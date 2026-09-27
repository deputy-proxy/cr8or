<?php

namespace App\Services;

use App\Contracts\KnowledgeEmbeddingProvider;
use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\KnowledgeEmbedding;
use Illuminate\Database\Eloquent\Builder;

final class KnowledgeSemanticRetrievalProvider implements KnowledgeRetrievalProvider
{
    public function __construct(private readonly KnowledgeEmbeddingProvider $provider) {}

    public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
    {
        $query = trim($request->query ?? $request->objective ?? '');
        $correlationId = $request->correlationId ?? 'knowledge-semantic-'.bin2hex(random_bytes(8));

        if ($query === '') {
            return new KnowledgeRetrievalResult('succeeded', $correlationId, metadata: ['mode' => 'semantic']);
        }

        $embeddingVersion = $this->provider->version();
        $queryVector = $this->provider->embed($query, $embeddingVersion);

        $embeddings = KnowledgeEmbedding::query()
            ->with(['unit.record.source', 'unit.record.document', 'unit.item', 'unit.version'])
            ->where('enterprise_id', $request->enterprise->getKey())
            ->where('embedding_version', $embeddingVersion)
            ->whereHas('record', static fn (Builder $record): Builder => $record->where('status', 'indexed'))
            ->get();

        $ranked = [];
        foreach ($embeddings as $embedding) {
            if ($embedding->content_hash !== $embedding->unit->content_hash) {
                continue;
            }

            $storedVector = $embedding->getAttribute('vector');
            $score = $this->cosine($queryVector, is_array($storedVector) ? array_values(array_map('floatval', $storedVector)) : []);
            if ($request->minimumRelevance() !== null && $score < (float) $request->minimumRelevance()) {
                continue;
            }

            $ranked[] = [$score, $embedding];
        }

        usort($ranked, static fn (array $left, array $right): int => ($right[0] <=> $left[0]) ?: ($left[1]->knowledge_index_unit_id <=> $right[1]->knowledge_index_unit_id));
        $ranked = array_slice($ranked, 0, $request->limit());

        return new KnowledgeRetrievalResult(
            'succeeded',
            $correlationId,
            array_map(fn (array $entry): KnowledgeRetrievalResultItem => $this->result($entry[1], $entry[0]), $ranked),
            count($ranked),
            ['mode' => 'semantic', 'embedding_version' => $embeddingVersion],
        );
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        if (count($left) === 0 || count($left) !== count($right)) {
            return 0.0;
        }

        return round(array_sum(array_map(static fn (float $a, float $b): float => $a * $b, $left, $right)), 4);
    }

    private function result(KnowledgeEmbedding $embedding, float $score): KnowledgeRetrievalResultItem
    {
        $unit = $embedding->unit;
        $item = $unit->item;
        $record = $unit->record;

        return new KnowledgeRetrievalResultItem(
            $item->getKey(),
            $item->title,
            $item->summary,
            $score,
            $record->source ? ['id' => $record->source->getKey(), 'name' => $record->source->name] : null,
            $record->document ? ['id' => $record->document->getKey(), 'title' => $record->document->title] : null,
            null,
            is_array($unit->getAttribute('references')) ? array_values(array_filter($unit->getAttribute('references'), static fn (mixed $reference): bool => is_array($reference))) : [],
            $unit->version ? ['id' => $unit->version->getKey(), 'version' => $unit->version->version] : null,
            ['embedding_version' => $embedding->embedding_version, 'unit_key' => $unit->unit_key],
        );
    }
}