<?php

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeHybridRetrievalProvider;
use App\Services\KnowledgeRetrievalService;

function hybridActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, Enterprise::factory()->create(['organization_id' => $organization])];
}

function fakeRetrievalProvider(array $items, string $mode): KnowledgeRetrievalProvider
{
    return new class($items, $mode) implements KnowledgeRetrievalProvider
    {
        public function __construct(private array $items, private string $mode) {}

        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            return new KnowledgeRetrievalResult('succeeded', $request->correlationId ?? 'generated', $this->items, count($this->items), ['mode' => $this->mode]);
        }
    };
}

it('combines lexical and semantic signals deterministically and deduplicates items', function (): void {
    [$user, $enterprise] = hybridActor();
    $approval = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Approval']);
    $budget = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Budget']);
    $lexicalItem = new KnowledgeRetrievalResultItem($approval->getKey(), 'Approval', relevance: 0.9);
    $semanticItem = new KnowledgeRetrievalResultItem($approval->getKey(), 'Approval', relevance: 0.7, version: ['id' => 5, 'version' => 2]);
    $other = new KnowledgeRetrievalResultItem($budget->getKey(), 'Budget', relevance: 0.6);

    $provider = new KnowledgeHybridRetrievalProvider(
        fakeRetrievalProvider([$lexicalItem, $other], 'lexical'),
        fakeRetrievalProvider([$semanticItem], 'semantic'),
    );

    $result = (new KnowledgeRetrievalService($provider))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'approval', mode: 'hybrid', limits: ['limit' => 5]),
    );

    expect($result->items)->toHaveCount(2)
        ->and($result->items[0]->knowledgeItemId)->toBe($approval->getKey())
        ->and($result->items[0]->relevance)->toBe(0.8)
        ->and($result->items[0]->version)->toMatchArray(['version' => 2]);
});

it('respects result bounds and stable tie-breaking', function (): void {
    [$user, $enterprise] = hybridActor();
    $first = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'A']);
    $second = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'B']);
    $provider = new KnowledgeHybridRetrievalProvider(
        fakeRetrievalProvider([
            new KnowledgeRetrievalResultItem($second->getKey(), 'B', relevance: 0.5),
            new KnowledgeRetrievalResultItem($first->getKey(), 'A', relevance: 0.5),
        ], 'lexical'),
        fakeRetrievalProvider([], 'semantic'),
    );

    $result = (new KnowledgeRetrievalService($provider))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'x', limits: ['limit' => 1]),
    );

    expect($result->items[0]->knowledgeItemId)->toBe($first->getKey());
});

it('preserves the canonical authorization boundary', function (): void {
    [$user] = hybridActor();
    $foreign = Enterprise::factory()->create();

    $provider = new KnowledgeHybridRetrievalProvider(
        fakeRetrievalProvider([], 'lexical'),
        fakeRetrievalProvider([], 'semantic'),
    );

    expect(fn () => (new KnowledgeRetrievalService($provider))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $foreign, query: 'secret'),
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
it('honors explicit lexical and semantic retrieval modes without hybridizing them', function (): void {
    [$user, $enterprise] = hybridActor();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Mode']);

    $provider = new KnowledgeHybridRetrievalProvider(
        fakeRetrievalProvider([new KnowledgeRetrievalResultItem($item->getKey(), 'Mode', relevance: 0.9)], 'lexical'),
        fakeRetrievalProvider([new KnowledgeRetrievalResultItem($item->getKey(), 'Mode', relevance: 0.7)], 'semantic'),
    );

    $lexical = $provider->retrieve(new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'mode',
        mode: 'lexical',
    ));

    $semantic = $provider->retrieve(new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'mode',
        mode: 'semantic',
    ));

    expect($lexical->items)->toHaveCount(1)
        ->and($lexical->items[0]->relevance)->toBe(0.9)
        ->and($lexical->metadata['mode'])->toBe('lexical')
        ->and($semantic->items)->toHaveCount(1)
        ->and($semantic->items[0]->relevance)->toBe(0.7)
        ->and($semantic->metadata['mode'])->toBe('semantic');
});