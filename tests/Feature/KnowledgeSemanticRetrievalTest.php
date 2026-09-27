<?php

use App\Contracts\KnowledgeEmbeddingProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeRetrievalService;
use App\Services\KnowledgeSemanticIndexService;
use App\Services\KnowledgeSemanticRetrievalProvider;
use Illuminate\Auth\Access\AuthorizationException;

function semanticActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, Enterprise::factory()->create(['organization_id' => $organization])];
}

function semanticUnit(Enterprise $enterprise, string $content): KnowledgeIndexUnit
{
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Knowledge']);
    $version = KnowledgeVersion::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'content' => $content]);
    $record = new KnowledgeIndexRecord;
    $record->forceFill([
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(),
        'knowledge_version_id' => $version->getKey(), 'unit_key' => 'chunk-0001',
        'representation_key' => hash('sha256', $item->getKey().':'.$version->getKey().':chunk-0001'), 'status' => 'indexed',
    ])->save();
    $unit = new KnowledgeIndexUnit;
    $unit->forceFill([
        'enterprise_id' => $enterprise->getKey(), 'knowledge_index_record_id' => $record->getKey(),
        'knowledge_item_id' => $item->getKey(), 'knowledge_version_id' => $version->getKey(),
        'unit_key' => 'chunk-0001', 'ordinal' => 1, 'content' => $content, 'content_hash' => hash('sha256', $content),
    ])->save();

    return $unit->refresh();
}

it('indexes and retrieves current semantic representations through provider-neutral boundaries', function (): void {
    [$user, $enterprise] = semanticActor();
    $provider = new class implements KnowledgeEmbeddingProvider
    {
        public function embed(string $text, string $modelVersion): array
        {
            return $text === 'approval policy' ? [1.0, 0.0] : [0.0, 1.0];
        }

        public function version(): string
        {
            return 'fake-v1';
        }
    };
    $unit = semanticUnit($enterprise, 'approval policy');
    (new KnowledgeSemanticIndexService($provider))->indexUnit($user, $unit);

    $result = (new KnowledgeRetrievalService(new KnowledgeSemanticRetrievalProvider($provider)))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'approval policy', mode: 'semantic', limits: ['limit' => 5]),
    );

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->relevance)->toBe(1.0)
        ->and($result->items[0]->version)->not->toBeNull()
        ->and($result->metadata['embedding_version'])->toBe('fake-v1');
});

it('does not use stale or mismatched embedding representations', function (): void {
    [$user, $enterprise] = semanticActor();
    $provider = new class implements KnowledgeEmbeddingProvider
    {
        public function embed(string $text, string $modelVersion): array
        {
            return [1.0, 0.0];
        }

        public function version(): string
        {
            return 'fake-v1';
        }
    };
    $unit = semanticUnit($enterprise, 'current content');
    $embedding = (new KnowledgeSemanticIndexService($provider))->indexUnit($user, $unit);
    $unit->update(['content_hash' => hash('sha256', 'changed')]);

    $result = (new KnowledgeRetrievalService(new KnowledgeSemanticRetrievalProvider($provider)))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'anything'),
    );

    expect($embedding->refresh())->not->toBeNull()
        ->and($result->items)->toBe([]);
});

it('rejects semantic indexing across Enterprise boundaries', function (): void {
    [$user] = semanticActor();
    $foreign = Enterprise::factory()->create();
    $unit = semanticUnit($foreign, 'secret');

    expect(fn () => (new KnowledgeSemanticIndexService(new class implements KnowledgeEmbeddingProvider
    {
        public function embed(string $text, string $modelVersion): array
        {
            return [1.0, 0.0];
        }

        public function version(): string
        {
            return 'fake-v1';
        }
    }))->indexUnit($user, $unit))->toThrow(AuthorizationException::class);
});