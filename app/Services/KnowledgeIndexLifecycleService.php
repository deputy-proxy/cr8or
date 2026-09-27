<?php

namespace App\Services;

use App\Enums\KnowledgeIndexStatus;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

final class KnowledgeIndexLifecycleService
{
    public function requestIndex(
        User $actor,
        KnowledgeItem $item,
        string $unitKey = 'root',
        ?string $correlationId = null,
    ): KnowledgeIndexRecord {
        Gate::forUser($actor)->authorize('view', $item);

        if (trim($unitKey) === '') {
            throw new InvalidArgumentException('Knowledge index unit key is required.');
        }

        $item->load(['source', 'document', 'latestVersion']);

        $versionId = $item->latestVersion?->getKey();
        $representationKey = hash('sha256', implode(':', [
            $item->getKey(),
            $versionId ?? 'unversioned',
            $unitKey,
        ]));

        $record = KnowledgeIndexRecord::query()->firstOrNew([
            'representation_key' => $representationKey,
        ]);

        $record->forceFill([
            'enterprise_id' => $item->enterprise_id,
            'knowledge_source_id' => $item->knowledge_source_id,
            'knowledge_document_id' => $item->knowledge_document_id,
            'knowledge_item_id' => $item->getKey(),
            'knowledge_version_id' => $versionId,
            'unit_key' => $unitKey,
            'status' => KnowledgeIndexStatus::PENDING,
            'content_hash' => null,
            'provider' => null,
            'provider_reference' => null,
            'failure_code' => null,
            'failure_message' => null,
            'indexed_at' => null,
            'invalidated_at' => null,
            'metadata' => array_filter([
                'correlation_id' => $correlationId,
            ], static fn (mixed $value): bool => $value !== null),
        ])->save();

        KnowledgeIndexRecord::query()
            ->where('knowledge_item_id', $item->getKey())
            ->whereKeyNot($record->getKey())
            ->get()
            ->each(function (KnowledgeIndexRecord $previous): void {
                if ($previous->getRawOriginal('status') === KnowledgeIndexStatus::REMOVED->value) {
                    return;
                }

                $previous->forceFill([
                    'status' => KnowledgeIndexStatus::STALE,
                    'invalidated_at' => now(),
                ])->save();
            });

        return $record->refresh();
    }

    public function markIndexed(
        User $actor,
        KnowledgeIndexRecord $record,
        string $provider,
        string $providerReference,
        string $contentHash,
    ): KnowledgeIndexRecord {
        Gate::forUser($actor)->authorize('view', $record->item);
        $record->refresh();

        if (in_array($record->getRawOriginal('status'), [KnowledgeIndexStatus::STALE->value, KnowledgeIndexStatus::REMOVED->value], true)) {
            throw new LogicException('A stale or removed Knowledge index record cannot become indexed.');
        }

        foreach ([$provider, $providerReference, $contentHash] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Indexed Knowledge representation metadata cannot be empty.');
            }
        }

        $record->forceFill([
            'status' => KnowledgeIndexStatus::INDEXED,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'content_hash' => $contentHash,
            'failure_code' => null,
            'failure_message' => null,
            'indexed_at' => now(),
            'invalidated_at' => null,
        ])->save();

        return $record->refresh();
    }

    public function markFailed(
        User $actor,
        KnowledgeIndexRecord $record,
        string $failureCode,
        string $failureMessage,
    ): KnowledgeIndexRecord {
        Gate::forUser($actor)->authorize('view', $record->item);
        $record->refresh();

        if ($record->getRawOriginal('status') === KnowledgeIndexStatus::REMOVED->value) {
            throw new LogicException('A removed Knowledge index record cannot become failed.');
        }

        $record->forceFill([
            'status' => KnowledgeIndexStatus::FAILED,
            'failure_code' => trim($failureCode),
            'failure_message' => trim($failureMessage),
            'indexed_at' => null,
        ])->save();

        return $record->refresh();
    }

    public function invalidate(
        User $actor,
        KnowledgeItem $item,
        string $reason = 'authoritative_knowledge_changed',
    ): int {
        Gate::forUser($actor)->authorize('view', $item);

        $records = KnowledgeIndexRecord::query()
            ->where('knowledge_item_id', $item->getKey())
            ->get();

        $updated = 0;

        foreach ($records as $record) {
            if ($record->getRawOriginal('status') === KnowledgeIndexStatus::REMOVED->value) {
                continue;
            }

            $record->forceFill([
                'status' => KnowledgeIndexStatus::STALE,
                'failure_code' => 'invalidated',
                'failure_message' => trim($reason) ?: 'authoritative_knowledge_changed',
                'invalidated_at' => now(),
            ])->save();

            $updated++;
        }

        return $updated;
    }

    public function remove(User $actor, KnowledgeIndexRecord $record, string $reason = 'authoritative_knowledge_removed'): KnowledgeIndexRecord
    {
        Gate::forUser($actor)->authorize('view', $record->item);
        $record->refresh();

        $record->forceFill([
            'status' => KnowledgeIndexStatus::REMOVED,
            'failure_code' => 'removed',
            'failure_message' => trim($reason) ?: 'authoritative_knowledge_removed',
            'invalidated_at' => now(),
        ])->save();

        return $record->refresh();
    }
}