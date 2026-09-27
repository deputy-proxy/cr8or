<?php

namespace App\Services;

use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class KnowledgeRetrievalProvenanceService
{
    public function normalize(User $actor, Enterprise $enterprise, KnowledgeRetrievalResult $result): KnowledgeRetrievalResult
    {
        $items = [];

        foreach ($result->items as $retrieved) {
            $item = KnowledgeItem::query()
                ->with(['source', 'document', 'references', 'latestVersion'])
                ->where('enterprise_id', $enterprise->getKey())
                ->find($retrieved->knowledgeItemId);

            if ($item === null) {
                throw new LogicException('Knowledge retrieval returned an item outside the requested Enterprise.');
            }

            Gate::forUser($actor)->authorize('view', $item);

            $versionId = $retrieved->version['id'] ?? $item->latestVersion?->getKey();
            $currentVersionId = $item->latestVersion?->getKey();

            /** @var list<array<string, mixed>> $references */
            $references = $item->references->map(fn ($reference): array => [
                'id' => $reference->getKey(),
                'type' => $reference->type,
                'label' => $reference->label,
                'locator' => $reference->locator,
            ])->values()->all();

            $items[] = new KnowledgeRetrievalResultItem(
                knowledgeItemId: $item->getKey(),
                title: $item->title,
                summary: $retrieved->summary ?? $item->summary,
                relevance: $retrieved->relevance,
                source: $retrieved->source ?? ($item->source ? ['id' => $item->source->getKey(), 'name' => $item->source->name] : null),
                document: $retrieved->document ?? ($item->document ? ['id' => $item->document->getKey(), 'title' => $item->document->title] : null),
                context: $retrieved->context,
                references: $retrieved->references !== [] ? $retrieved->references : $references,
                version: $retrieved->version ?? ($item->latestVersion ? ['id' => $item->latestVersion->getKey(), 'version' => $item->latestVersion->version] : null),
                metadata: $retrieved->metadata + [
                    'provenance_current' => $versionId === $currentVersionId,
                    'authoritative_knowledge_item_id' => $item->getKey(),
                ],
            );
        }

        return new KnowledgeRetrievalResult(
            $result->status,
            $result->correlationId,
            $items,
            $result->candidateCount,
            $result->metadata + ['provenance_normalized' => true],
        );
    }
}