<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class KnowledgeResourceService
{
    public function __construct(
        private readonly KnowledgeIndexingService $indexing,
        private readonly KnowledgeIndexLifecycleService $lifecycle,
    ) {}

    /** @return array<string, mixed> */
    public function createIndex(User $actor, Enterprise $enterprise, KnowledgeItem $item, ?string $correlationId = null): array
    {
        $this->authorizeItem($actor, $enterprise, $item);

        $result = $this->indexing->indexItem($actor, $item, $correlationId);

        $records = KnowledgeIndexRecord::query()
            ->with(['item', 'version', 'source', 'document'])
            ->where('enterprise_id', $enterprise->getKey())
            ->where('knowledge_item_id', $item->getKey())
            ->where('status', 'indexed')
            ->orderBy('ordinal')
            ->get();

        return [
            'correlation_id' => $result->correlationId,
            'knowledge_item_id' => $item->getKey(),
            'indexed_units' => $result->indexedUnits,
            'failed_units' => $result->failedUnits,
            'indexes' => $records->map(fn (KnowledgeIndexRecord $record): array => $this->serializeIndex($record))->values()->all(),
        ];
    }

    public function getIndex(User $actor, Enterprise $enterprise, KnowledgeIndexRecord $record): KnowledgeIndexRecord
    {
        $record->loadMissing(['item', 'version', 'source', 'document']);
        $this->authorizeItem($actor, $enterprise, $record->item);

        return $record;
    }

    /** @return LengthAwarePaginator<int, KnowledgeIndexRecord> */
    public function listIndexes(
        User $actor,
        Enterprise $enterprise,
        ?int $knowledgeItemId = null,
        ?string $status = null,
        ?string $search = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $query = KnowledgeIndexRecord::query()
            ->with(['item', 'version', 'source', 'document'])
            ->where('enterprise_id', $enterprise->getKey())
            ->when($knowledgeItemId !== null, fn ($q) => $q->where('knowledge_item_id', $knowledgeItemId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== null && trim($search) !== '', function ($q) use ($search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $q->where(function ($nested) use ($term): void {
                    $nested->whereRaw('LOWER(unit_key) LIKE ?', [$term])
                        ->orWhereHas('item', fn ($item) => $item->whereRaw('LOWER(title) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('id');

        return $query->paginate(min(50, max(1, $perPage)));
    }

    public function updateIndex(User $actor, Enterprise $enterprise, KnowledgeIndexRecord $record, ?string $correlationId = null): KnowledgeIndexRecord
    {
        $record->loadMissing('item');
        $this->authorizeItem($actor, $enterprise, $record->item);

        $unitKey = $record->unit_key;
        $this->indexing->indexItem($actor, $record->item, $correlationId);

        $refreshed = KnowledgeIndexRecord::query()
            ->with(['item', 'version', 'source', 'document'])
            ->where('enterprise_id', $enterprise->getKey())
            ->where('knowledge_item_id', $record->knowledge_item_id)
            ->where('unit_key', $unitKey)
            ->where('status', 'indexed')
            ->latest('id')
            ->first();

        if ($refreshed === null) {
            throw new InvalidArgumentException('Knowledge index unit could not be rebuilt from authoritative Knowledge.');
        }

        return $refreshed;
    }

    public function createUnit(User $actor, Enterprise $enterprise, KnowledgeItem $item, string $unitKey, ?string $correlationId = null): KnowledgeIndexUnit
    {
        $this->authorizeItem($actor, $enterprise, $item);

        $this->indexing->indexItem($actor, $item, $correlationId);

        $unit = KnowledgeIndexUnit::query()
            ->with(['record', 'item', 'version'])
            ->where('enterprise_id', $enterprise->getKey())
            ->where('knowledge_item_id', $item->getKey())
            ->where('unit_key', $unitKey)
            ->whereHas('record', fn ($q) => $q->where('status', 'indexed'))
            ->latest('id')
            ->first();

        if ($unit === null) {
            throw new InvalidArgumentException("Knowledge unit [{$unitKey}] does not exist in the authoritative Knowledge representation.");
        }

        return $unit;
    }

    public function getUnit(User $actor, Enterprise $enterprise, KnowledgeIndexUnit $unit): KnowledgeIndexUnit
    {
        $unit->loadMissing(['record', 'item', 'version']);
        $this->authorizeItem($actor, $enterprise, $unit->item);

        return $unit;
    }

    /** @return LengthAwarePaginator<int, KnowledgeIndexUnit> */
    public function listUnits(
        User $actor,
        Enterprise $enterprise,
        ?int $knowledgeIndexId = null,
        ?int $knowledgeItemId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return KnowledgeIndexUnit::query()
            ->with(['record', 'item', 'version'])
            ->where('enterprise_id', $enterprise->getKey())
            ->when($knowledgeIndexId !== null, fn ($q) => $q->where('knowledge_index_record_id', $knowledgeIndexId))
            ->when($knowledgeItemId !== null, fn ($q) => $q->where('knowledge_item_id', $knowledgeItemId))
            ->whereHas('record', fn ($q) => $q->where('status', 'indexed'))
            ->orderBy('knowledge_item_id')
            ->orderBy('ordinal')
            ->paginate(min(50, max(1, $perPage)));
    }

    public function updateUnit(User $actor, Enterprise $enterprise, KnowledgeIndexUnit $unit, ?string $correlationId = null): KnowledgeIndexUnit
    {
        $unit->loadMissing(['record', 'item']);
        $this->authorizeItem($actor, $enterprise, $unit->item);

        return $this->createUnit($actor, $enterprise, $unit->item, $unit->unit_key, $correlationId);
    }

    public function archiveUnit(User $actor, Enterprise $enterprise, KnowledgeIndexUnit $unit, string $reason = 'mcp_archive'): KnowledgeIndexUnit
    {
        $unit->loadMissing(['record', 'item', 'version']);
        $this->authorizeItem($actor, $enterprise, $unit->item);
        $this->lifecycle->remove($actor, $unit->record, $reason);

        return $unit->refresh()->load(['record', 'item', 'version']);
    }

    /** @return array<string, mixed> */
    public function serializeIndex(KnowledgeIndexRecord $record): array
    {
        return [
            'id' => $record->getKey(),
            'enterprise_id' => $record->enterprise_id,
            'knowledge_item_id' => $record->knowledge_item_id,
            'knowledge_version_id' => $record->knowledge_version_id,
            'knowledge_source_id' => $record->knowledge_source_id,
            'knowledge_document_id' => $record->knowledge_document_id,
            'unit_key' => $record->unit_key,
            'status' => $this->statusValue($record->status),
            'content_hash' => $record->content_hash,
            'provider' => $record->provider,
            'provider_reference' => $record->provider_reference,
            'failure_code' => $record->failure_code,
            'failure_message' => $record->failure_message,
            'indexed_at' => $record->indexed_at !== null ? \Carbon\CarbonImmutable::parse((string) $record->indexed_at)->toISOString() : null,
            'invalidated_at' => $record->invalidated_at !== null ? \Carbon\CarbonImmutable::parse((string) $record->invalidated_at)->toISOString() : null,
            'metadata' => $record->metadata,
            'item' => $record->item ? [
                'id' => $record->item->getKey(),
                'title' => $record->item->title,
                'type' => $record->item->type,
            ] : null,
            'version' => $record->version ? [
                'id' => $record->version->getKey(),
                'version' => $record->version->version,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeUnit(KnowledgeIndexUnit $unit): array
    {
        return [
            'id' => $unit->getKey(),
            'enterprise_id' => $unit->enterprise_id,
            'knowledge_index_record_id' => $unit->knowledge_index_record_id,
            'knowledge_item_id' => $unit->knowledge_item_id,
            'knowledge_version_id' => $unit->knowledge_version_id,
            'unit_key' => $unit->unit_key,
            'ordinal' => $unit->ordinal,
            'content' => $unit->content,
            'content_hash' => $unit->content_hash,
            'heading_path' => $unit->heading_path,
            'references' => $unit->references,
            'metadata' => $unit->metadata,
            'record_status' => $unit->record?->status !== null ? $this->statusValue($unit->record->status) : null,
        ];
    }

    private function statusValue(mixed $status): string
    {
        if (is_object($status) && property_exists($status, 'value')) {
            return (string) $status->value;
        }

        return (string) $status;
    }

    private function authorizeItem(User $actor, Enterprise $enterprise, KnowledgeItem $item): void
    {
        if ((int) $item->enterprise_id !== (int) $enterprise->getKey()) {
            throw new InvalidArgumentException('Knowledge resource does not belong to the requested Enterprise.');
        }

        Gate::forUser($actor)->authorize('view', $item);
    }
}