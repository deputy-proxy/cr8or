<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeVersion;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class KnowledgeItemService
{
    private readonly KnowledgeIndexLifecycleService $indexLifecycle;

    public function __construct(KnowledgeIndexLifecycleService $indexLifecycle)
    {
        $this->indexLifecycle = $indexLifecycle;
    }

    /**
     * @param   array<string, mixed>  $attributes  
     * @return array<string, mixed>
     */
    public function create(User $actor, Enterprise $enterprise, array $attributes): array
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [KnowledgeItem::class, $enterprise]);
        $this->assertRelationships($enterprise, $attributes);

        return DB::transaction(function () use ($enterprise, $attributes): array {
            $item = KnowledgeItem::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'knowledge_source_id' => $attributes['knowledge_source_id'] ?? null,
                'knowledge_document_id' => $attributes['knowledge_document_id'] ?? null,
                'knowledge_context_id' => $attributes['knowledge_context_id'] ?? null,
                'title' => $attributes['title'],
                'type' => $attributes['type'] ?? 'fact',
                'summary' => $attributes['summary'] ?? null,
            ]);
            $item->versions()->create([
                'enterprise_id' => $enterprise->getKey(),
                'content' => $attributes['content'],
                'context_snapshot' => $attributes['context_snapshot'] ?? null,
            ]);

            $result = $this->serialize($item->refresh()->load(['source', 'document', 'context', 'latestVersion', 'references', 'specifications']));
            if (isset($result['version'])) {
                $result['version']['content'] = $result['content'] ?? null;
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function get(User $actor, Enterprise $enterprise, KnowledgeItem $item): array
    {
        $this->assertEnterprise($enterprise, $item);
        Gate::forUser($actor)->authorize('view', $item);

        return $this->serialize($item->load(['source', 'document', 'context', 'latestVersion', 'references', 'specifications']));
    }

    /** @return LengthAwarePaginator<int, KnowledgeItem> */
    public function list(User $actor, Enterprise $enterprise, ?string $search = null, ?string $type = null, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return KnowledgeItem::query()
            ->with(['source', 'document', 'context', 'latestVersion'])
            ->where('enterprise_id', $enterprise->getKey())
            ->when($type !== null && $type !== '', fn ($query) => $query->where('type', $type))
            ->when($search !== null && trim($search) !== '', function ($query) use ($search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(function ($nested) use ($term): void {
                    $nested->whereRaw('LOWER(title) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(summary) LIKE ?', [$term]);
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(min(50, max(1, $perPage)), ['*'], 'page', max(1, $page));
    }

    /**
     * @param   array<string, mixed>  $attributes  
     * @return array<string, mixed>
     */
    public function update(User $actor, Enterprise $enterprise, KnowledgeItem $item, array $attributes): array
    {
        $this->assertEnterprise($enterprise, $item);
        Gate::forUser($actor)->authorize('update', $item);

        $mutable = array_intersect_key($attributes, array_flip(['title', 'type', 'summary', 'knowledge_source_id', 'knowledge_document_id', 'knowledge_context_id']));
        $this->assertRelationships($enterprise, $attributes);
        $hasVersionData = array_key_exists('content', $attributes) || array_key_exists('context_snapshot', $attributes);

        if ($mutable === [] && $hasVersionData === false) {
            throw ValidationException::withMessages(['knowledge_item_id' => 'At least one mutable Knowledge Item field must be supplied.']);
        }

        return DB::transaction(function () use ($actor, $enterprise, $item, $mutable, $hasVersionData, $attributes): array {
            $locked = KnowledgeItem::query()
                ->where('enterprise_id', $enterprise->getKey())
                ->lockForUpdate()
                ->whereKey($item->getKey())
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            $changed = false;
            foreach ($mutable as $key => $value) {
                if ($locked->getAttribute($key) !== $value) {
                    $changed = true;
                    $locked->setAttribute($key, $value);
                }
            }

            if ($changed) {
                $locked->save();
            }

            if ($hasVersionData) {
                $latest = $locked->versions()->latest('version')->first();
                $content = array_key_exists('content', $attributes)
                    ? (string) $attributes['content']
                    : $latest?->content;
                $contextSnapshot = array_key_exists('context_snapshot', $attributes)
                    ? $attributes['context_snapshot']
                    : $latest?->context_snapshot;

                if ($content === null) {
                    throw ValidationException::withMessages(['content' => 'Content is required to create the first Knowledge Item version.']);
                }

                if ($latest === null || $latest->content !== $content || $latest->context_snapshot !== $contextSnapshot) {
                    KnowledgeVersion::query()->create([
                        'enterprise_id' => $enterprise->getKey(),
                        'knowledge_item_id' => $locked->getKey(),
                        'content' => $content,
                        'context_snapshot' => $contextSnapshot,
                    ]);
                    $locked->touch();
                    $changed = true;
                }
            }

            if ($changed) {
                $this->indexLifecycle->invalidate($actor, $locked, 'authoritative_knowledge_item_updated');
            }

            return $this->serialize($locked->refresh()->load(['source', 'document', 'context', 'latestVersion', 'references', 'specifications']));
        });
    }

    /** @return array<string, mixed> */
    public function serialize(KnowledgeItem $item, bool $includeDetails = true): array
    {
        $version = $item->latestVersion;

        $data = [
            'id' => $item->getKey(),
            'enterprise_id' => $item->enterprise_id,
            'knowledge_source_id' => $item->knowledge_source_id,
            'knowledge_document_id' => $item->knowledge_document_id,
            'knowledge_context_id' => $item->knowledge_context_id,
            'title' => $item->title,
            'type' => $item->type,
            'summary' => $item->summary,
            'version' => $version ? array_filter([
                'id' => $version->getKey(),
                'version' => $version->version,
                'recorded_at' => \Carbon\CarbonImmutable::parse($version->recorded_at)->toISOString(),
                ...($includeDetails ? ['context_snapshot' => $version->context_snapshot] : []),
            ], fn ($value): bool => $value !== null) : null,
            'source' => $item->source ? ['id' => $item->source->getKey(), 'name' => $item->source->name, 'type' => $item->source->type] : null,
            'document' => $item->document ? ['id' => $item->document->getKey(), 'title' => $item->document->title, 'identifier' => $item->document->identifier] : null,
            'context' => $item->context ? ['id' => $item->context->getKey(), 'name' => $item->context->name, 'type' => $item->context->type] : null,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];

        if ($includeDetails) {
            $data['content'] = $version?->content;
            $data['references'] = $item->references->map(fn ($reference): array => [
                'id' => $reference->getKey(), 'knowledge_document_id' => $reference->knowledge_document_id,
                'type' => $reference->type, 'label' => $reference->label, 'locator' => $reference->locator,
            ])->values()->all();
            $data['specifications'] = $item->specifications->map(fn ($specification): array => [
                'id' => $specification->getKey(), 'name' => $specification->name,
                'version' => $specification->version, 'content' => $specification->content,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @param   array<string, mixed>  $attributes  
     */
    private function assertRelationships(Enterprise $enterprise, array $attributes): void
    {
        foreach (['knowledge_source_id', 'knowledge_document_id', 'knowledge_context_id'] as $field) {
            if (array_key_exists($field, $attributes) === false || $attributes[$field] === null) {
                continue;
            }

            $exists = match ($field) {
                'knowledge_source_id' => KnowledgeSource::query()->where('enterprise_id', $enterprise->getKey())->whereKey($attributes[$field])->exists(),
                'knowledge_document_id' => KnowledgeDocument::query()->where('enterprise_id', $enterprise->getKey())->whereKey($attributes[$field])->exists(),
                default => KnowledgeContext::query()->where('enterprise_id', $enterprise->getKey())->whereKey($attributes[$field])->exists(),
            };

            if ($exists === false) {
                throw ValidationException::withMessages([$field => 'The selected Knowledge relationship must belong to the requested Enterprise.']);
            }
        }
    }

    private function assertEnterprise(Enterprise $enterprise, KnowledgeItem $item): void
    {
        if ((int) $item->enterprise_id !== (int) $enterprise->getKey()) {
            abort(404);
        }
    }
}
