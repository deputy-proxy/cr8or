<?php

namespace App\Services;

use App\Contracts\KnowledgeEmbeddingProvider;
use App\Models\KnowledgeEmbedding;
use App\Models\KnowledgeIndexUnit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class KnowledgeSemanticIndexService
{
    public function __construct(private readonly KnowledgeEmbeddingProvider $provider) {}

    public function indexUnit(User $actor, KnowledgeIndexUnit $unit): KnowledgeEmbedding
    {
        Gate::forUser($actor)->authorize('view', $unit->item);

        $record = $unit->record;
        $record->refresh();

        if ($record->getRawOriginal('status') !== 'indexed') {
            throw new \LogicException('Only current indexed representations can receive semantic embeddings.');
        }

        $version = $unit->knowledge_version_id;
        $embeddingVersion = $this->provider->version();
        $hash = $unit->content_hash;
        $vector = $this->provider->embed($unit->content, $embeddingVersion);

        return KnowledgeEmbedding::query()->updateOrCreate(
            ['knowledge_index_unit_id' => $unit->getKey(), 'embedding_version' => $embeddingVersion],
            [
                'enterprise_id' => $unit->enterprise_id,
                'knowledge_index_record_id' => $unit->knowledge_index_record_id,
                'knowledge_version_id' => $version,
                'content_hash' => $hash,
                'vector' => $vector,
            ],
        );
    }
}