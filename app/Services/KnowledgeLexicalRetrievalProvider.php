<?php

namespace App\Services;

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\KnowledgeIndexUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class KnowledgeLexicalRetrievalProvider implements KnowledgeRetrievalProvider
{
    public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
    {
        $query = trim($request->query ?? $request->objective ?? '');
        $correlationId = $request->correlationId ?? 'knowledge-lexical-'.bin2hex(random_bytes(8));

        if ($query === '') {
            return new KnowledgeRetrievalResult('succeeded', $correlationId);
        }

        $terms = $this->terms($query);

        if ($terms === []) {
            return new KnowledgeRetrievalResult('succeeded', $correlationId);
        }

        $builder = KnowledgeIndexUnit::query()
            ->with(['record.source', 'record.document', 'item', 'version'])
            ->where('knowledge_index_units.enterprise_id', $request->enterprise->getKey())
            ->whereHas('record', static fn (Builder $record): Builder => $record->where('status', 'indexed'))
            ->where(function (Builder $unit) use ($terms): void {
                foreach ($terms as $term) {
                    $unit->orWhereRaw('LOWER(knowledge_index_units.content) LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });

        $units = $builder->get();

        $scored = [];

        foreach ($units as $unit) {
            $score = $this->score($unit, $terms);

            if ($request->minimumRelevance() !== null && $score < (float) $request->minimumRelevance()) {
                continue;
            }

            $itemId = $unit->knowledge_item_id;
            if (! isset($scored[$itemId]) || $score > $scored[$itemId]['score']) {
                $scored[$itemId] = ['score' => $score, 'unit' => $unit];
            }
        }

        uasort($scored, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];

            if ($score !== 0) {
                return $score;
            }

            return $left['unit']->knowledge_item_id <=> $right['unit']->knowledge_item_id;
        });

        $items = array_slice($scored, 0, $request->limit());

        return new KnowledgeRetrievalResult(
            status: 'succeeded',
            correlationId: $correlationId,
            items: array_map(fn (array $entry): KnowledgeRetrievalResultItem => $this->result($entry['unit'], $entry['score']), $items),
            candidateCount: count($scored),
            metadata: [
                'mode' => 'lexical',
                'term_count' => count($terms),
            ],
        );
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/\s+/', Str::squish($query)) ?: [],
            static fn (string $term): bool => mb_strlen($term) >= 2,
        )));
    }

    /** @param list<string> $terms */
    private function score(KnowledgeIndexUnit $unit, array $terms): float
    {
        $content = mb_strtolower($unit->content);
        $title = mb_strtolower($unit->item->title);

        $score = 0.0;

        foreach ($terms as $term) {
            $contentHits = substr_count($content, mb_strtolower($term));
            $titleHits = substr_count($title, mb_strtolower($term));
            $score += min(0.65, $contentHits * 0.1);
            $score += min(0.35, $titleHits * 0.35);
        }

        return round(min(1.0, $score), 4);
    }

    private function result(KnowledgeIndexUnit $unit, float $score): KnowledgeRetrievalResultItem
    {
        $record = $unit->record;
        $item = $unit->item;
        $version = $unit->version;

        $references = $unit->getAttribute('references');
        $references = is_array($references) ? array_values(array_filter($references, static fn (mixed $reference): bool => is_array($reference))) : [];

        return new KnowledgeRetrievalResultItem(
            knowledgeItemId: $item->getKey(),
            title: $item->title,
            summary: $item->summary,
            relevance: $score,
            source: $record?->source ? [
                'id' => $record->source->getKey(),
                'name' => $record->source->name,
            ] : null,
            document: $record?->document ? [
                'id' => $record->document->getKey(),
                'title' => $record->document->title,
            ] : null,
            context: null,
            references: $references,
            version: $version ? [
                'id' => $version->getKey(),
                'version' => $version->version,
            ] : null,
            metadata: [
                'unit_key' => $unit->unit_key,
                'ordinal' => $unit->ordinal,
                'content_hash' => $unit->content_hash,
            ],
        );
    }
}