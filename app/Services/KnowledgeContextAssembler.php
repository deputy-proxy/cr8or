<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

final class KnowledgeContextAssembler
{
    private const CONTEXT_LIMIT = 100;

    private const ITEM_LIMIT = 100;

    private const REFERENCE_LIMIT = 100;

    /**
     * Retrieve bounded, authorization-scoped Knowledge context for an Agent.
     *
     * KnowledgeItem records remain the authoritative content boundary. Related
     * source, document, context, version and reference records provide provenance.
     *
     * @return array<string, mixed>
     */
    public function assemble(User $user, Enterprise $enterprise): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $contexts = $this->authorizedContexts($user, $enterprise);
        $items = $this->authorizedItems($user, $enterprise);

        $references = KnowledgeReference::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereIn('knowledge_item_id', $items->modelKeys())
            ->with('document')
            ->orderBy('knowledge_item_id')
            ->orderBy('id')
            ->limit(self::REFERENCE_LIMIT)
            ->get()
            ->filter(fn (KnowledgeReference $reference): bool => Gate::forUser($user)->allows('view', $reference));

        /** @var Collection<int, KnowledgeReference> $references */
        $references = $references->values();

        return [
            'enterprise' => $this->enterpriseIdentity($enterprise),
            'contexts' => $contexts->map(fn (KnowledgeContext $context) => [
                'id' => $context->getKey(),
                'name' => $context->name,
                'type' => $context->type,
                'description' => $context->description,
                'data' => $context->data,
            ])->all(),
            'items' => $items->map(fn (KnowledgeItem $item): array => $this->itemData($user, $item, $references))->all(),

        ];
    }

    /** @return Collection<int, KnowledgeContext> */
    private function authorizedContexts(User $user, Enterprise $enterprise): Collection
    {
        return $enterprise->knowledgeContexts()
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get()
            ->filter(fn (KnowledgeContext $context): bool => Gate::forUser($user)->allows('view', $context))
            ->values();
    }

    /** @return Collection<int, KnowledgeItem> */
    private function authorizedItems(User $user, Enterprise $enterprise): Collection
    {
        return $enterprise->knowledgeItems()
            ->with(['source', 'document', 'context', 'latestVersion'])
            ->orderBy('id')
            ->limit(self::ITEM_LIMIT)
            ->get()
            ->filter(fn (KnowledgeItem $item): bool => Gate::forUser($user)->allows('view', $item))
            ->values();
    }

    /**
     * @param  Collection<int, KnowledgeReference>  $references
     * @return array<string, mixed>
     */
    private function itemData(User $user, KnowledgeItem $item, Collection $references): array
    {
        $version = $item->latestVersion;

        if ($version !== null && ! Gate::forUser($user)->allows('view', $version)) {
            $version = null;
        }

        return [
            'id' => $item->getKey(),
            'title' => $item->title,
            'type' => $item->type,
            'summary' => $item->summary,
            'context' => $this->context($item),
            'source' => $this->source($item),
            'document' => $this->document($item),
            'version' => $version === null ? null : [
                'id' => $version->getKey(),
                'version' => $version->version,
                'content' => $version->content,
                'recorded_at' => $this->recordedAt($version),
            ],
            'references' => $references
                ->where('knowledge_item_id', $item->getKey())
                ->map(fn (KnowledgeReference $reference): array => [
                    'id' => $reference->getKey(),
                    'type' => $reference->type,
                    'label' => $reference->label,
                    'locator' => $reference->locator,
                    'document_id' => $reference->knowledge_document_id,
                    'document' => $reference->document === null || $reference->document->enterprise_id !== $item->enterprise_id
                        ? null
                        : [
                            'id' => $reference->document->getKey(),
                            'title' => $reference->document->title,
                            'identifier' => $reference->document->identifier,
                        ],
                    'metadata' => $reference->metadata,
                ])
                ->values()
                ->all(),
        ];
    }

    private function recordedAt(KnowledgeVersion $version): ?string
    {
        $value = $version->getAttribute('recorded_at');

        return $value instanceof \DateTimeInterface
            ? $value->format(DATE_ATOM)
            : ($value === null ? null : (string) $value);
    }

    /** @return array<string, mixed>|null */
    private function context(KnowledgeItem $item): ?array
    {
        $context = $item->context;

        return $context === null || $context->enterprise_id !== $item->enterprise_id
            ? null
            : [
                'id' => $context->getKey(),
                'name' => $context->name,
                'type' => $context->type,
                'description' => $context->description,
                'data' => $context->data,
            ];
    }

    /** @return array<string, mixed>|null */
    private function source(KnowledgeItem $item): ?array
    {
        $source = $item->source;

        return $source === null || $source->enterprise_id !== $item->enterprise_id
            ? null
            : [
                'id' => $source->getKey(),
                'name' => $source->name,
                'type' => $source->type,
                'uri' => $source->uri,
            ];
    }

    /** @return array<string, mixed>|null */
    private function document(KnowledgeItem $item): ?array
    {
        $document = $item->document;

        return $document === null || $document->enterprise_id !== $item->enterprise_id
            ? null
            : [
                'id' => $document->getKey(),
                'title' => $document->title,
                'identifier' => $document->identifier,
                'status' => $document->status,
            ];
    }

    /** @return array{id: int|string, name: string, slug: string, status: string} */
    private function enterpriseIdentity(Enterprise $enterprise): array
    {
        return [
            'id' => $enterprise->getKey(),
            'name' => $enterprise->name,
            'slug' => $enterprise->slug,
            'status' => $enterprise->status,
        ];
    }
}