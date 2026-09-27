<?php

namespace App\Services;

use App\Data\KnowledgeIndexingResult;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class KnowledgeIndexingService
{
    public function __construct(
        private readonly KnowledgeContentNormalizationService $normalizer,
        private readonly KnowledgeIndexLifecycleService $lifecycle,
    ) {}

    public function indexItem(User $actor, KnowledgeItem $item, ?string $correlationId = null): KnowledgeIndexingResult
    {
        Gate::forUser($actor)->authorize('view', $item);

        $correlationId ??= 'knowledge-index-'.bin2hex(random_bytes(8));
        $units = $this->normalizer->normalizeItem($actor, $item);

        $indexed = 0;
        $failed = 0;

        foreach ($units as $unit) {
            $record = $this->lifecycle->requestIndex($actor, $item, $unit->unitKey, $correlationId);

            try {
                DB::transaction(function () use ($actor, $unit, $correlationId, $record): void {
                    KnowledgeIndexUnit::query()->updateOrCreate(
                        ['knowledge_index_record_id' => $record->getKey(), 'unit_key' => $unit->unitKey],
                        [
                            'enterprise_id' => $unit->enterpriseId,
                            'knowledge_item_id' => $unit->knowledgeItemId,
                            'knowledge_version_id' => $unit->versionId,
                            'ordinal' => $unit->ordinal,
                            'content' => $unit->content,
                            'content_hash' => $unit->metadata['content_hash'],
                            'heading_path' => $unit->headingPath,
                            'references' => $unit->references,
                            'metadata' => $unit->metadata + ['correlation_id' => $correlationId],
                        ],
                    );

                    $this->lifecycle->markIndexed(
                        $actor,
                        $record,
                        'cr8or-index',
                        (string) $record->getKey(),
                        $unit->metadata['content_hash'],
                    );
                });

                $indexed++;
            } catch (\Throwable) {
                $this->lifecycle->markFailed(
                    $actor,
                    $record,
                    'indexing_failed',
                    'Search representation update failed.',
                );
                $failed++;
            }
        }

        if ($units !== [] && $indexed === 0) {
            throw new RuntimeException('Knowledge indexing failed for every normalized unit.');
        }

        return new KnowledgeIndexingResult($correlationId, $indexed, $failed);
    }

    public function removeItem(User $actor, KnowledgeItem $item): int
    {
        Gate::forUser($actor)->authorize('view', $item);

        $records = $item->indexRecords()->get();
        $removed = 0;

        foreach ($records as $record) {
            $this->lifecycle->remove($actor, $record);
            $removed++;
        }

        return $removed;
    }
}