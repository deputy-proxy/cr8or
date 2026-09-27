<?php

use App\Data\KnowledgeRetrievalRequest;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeLexicalRetrievalProvider;
use App\Services\KnowledgeRetrievalService;
use Illuminate\Auth\Access\AuthorizationException;

function lexicalActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    return [$user, $enterprise];
}

function lexicalUnit(Enterprise $enterprise, string $title, string $content, int $version = 1): KnowledgeItem
{
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => $title]);
    $knowledgeVersion = KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'version' => $version,
        'content' => $content,
    ]);
    $record = new KnowledgeIndexRecord;
    $record->forceFill([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_version_id' => $knowledgeVersion->getKey(),
        'unit_key' => 'chunk-0001',
        'representation_key' => hash('sha256', $item->getKey().':'.$knowledgeVersion->getKey().':chunk-0001'),
        'status' => 'indexed',
    ])->save();
    $unit = new KnowledgeIndexUnit;
    $unit->forceFill([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_index_record_id' => $record->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_version_id' => $knowledgeVersion->getKey(),
        'unit_key' => 'chunk-0001',
        'ordinal' => 1,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
    ])->save();

    return $item;
}

it('retrieves bounded lexical results with relevance and provenance', function (): void {
    [$user, $enterprise] = lexicalActor();
    $matching = lexicalUnit($enterprise, 'Approval policy', 'Approval threshold requires a manager.');
    lexicalUnit($enterprise, 'Unrelated policy', 'Holiday scheduling and leave.');

    $request = new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'approval threshold',
        mode: 'lexical',
        limits: ['limit' => 1],
    );

    $result = (new KnowledgeRetrievalService(new KnowledgeLexicalRetrievalProvider))->retrieve($request);

    expect($result->succeeded())
        ->and($result->items)->toHaveCount(1)
        ->and($result->items[0]->knowledgeItemId)->toBe($matching->getKey())
        ->and($result->items[0]->relevance)->toBeGreaterThan(0)
        ->and($result->items[0]->version)->not->toBeNull()
        ->and($result->metadata['mode'])->toBe('lexical');
});

it('returns deterministic empty results for empty or unmatched queries', function (): void {
    [$user, $enterprise] = lexicalActor();
    lexicalUnit($enterprise, 'Policy', 'Approval threshold.');

    $provider = new KnowledgeLexicalRetrievalProvider;

    $empty = $provider->retrieve(new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'x',
    ));
    $none = $provider->retrieve(new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'quantum banana',
    ));

    expect($empty->items)->toBe([])
        ->and($none->items)->toBe([]);
});

it('never returns indexed units from another Enterprise', function (): void {
    [$user, $enterprise] = lexicalActor();
    $foreign = Enterprise::factory()->create();
    lexicalUnit($foreign, 'Secret policy', 'approval secret');
    lexicalUnit($enterprise, 'Local policy', 'approval local');

    $request = new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'approval',
    );

    $result = (new KnowledgeRetrievalService(new KnowledgeLexicalRetrievalProvider))->retrieve($request);

    expect(array_column($result->items, 'knowledgeItemId'))->not->toContain(
        KnowledgeItem::query()->where('enterprise_id', $foreign->getKey())->value('id'),
    );
});

it('rejects unauthorized Enterprise retrieval before querying the index', function (): void {
    [$user] = lexicalActor();
    $foreign = Enterprise::factory()->create();

    expect(fn () => (new KnowledgeRetrievalService(new KnowledgeLexicalRetrievalProvider))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $foreign, query: 'secret'),
    ))->toThrow(AuthorizationException::class);
});