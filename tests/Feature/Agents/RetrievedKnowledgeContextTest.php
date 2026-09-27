<?php

use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentContextBuilder;
use App\Services\Context\Providers\RetrievedKnowledgeContextProvider;
use App\Services\KnowledgeRetrievalService;

function retrievedKnowledgeActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, Enterprise::factory()->create(['organization_id' => $organization])];
}

function fakeKnowledgeRetrieval(array $items): KnowledgeRetrievalService
{
    return new KnowledgeRetrievalService(new class($items) implements \App\Contracts\KnowledgeRetrievalProvider
    {
        public function __construct(private array $items) {}

        public function retrieve(\App\Data\KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            return new KnowledgeRetrievalResult('succeeded', $request->correlationId ?? 'retrieved-test', $this->items, count($this->items));
        }
    });
}

it('adds bounded retrieved Knowledge as a separate context section', function (): void {
    [$user, $enterprise] = retrievedKnowledgeActor();
    $first = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'First']);
    $second = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Second']);

    $provider = new RetrievedKnowledgeContextProvider(fakeKnowledgeRetrieval([
        new KnowledgeRetrievalResultItem($first->getKey(), 'First', relevance: 0.9, references: [['locator' => 'a']]),
        new KnowledgeRetrievalResultItem($second->getKey(), 'Second', relevance: 0.8, references: [['locator' => 'b']]),
    ]));

    $section = $provider->provide($user, $enterprise, [
        'retrieved_knowledge' => ['query' => 'approval', 'limit' => 2, 'budget' => 1000],
    ])[0];

    expect($section->name)->toBe('retrieved_knowledge')
        ->and($section->data['items'])->toHaveCount(2)
        ->and($section->data['items'][0]['references'])->not->toBeEmpty()
        ->and($section->data['selection']['estimated_tokens'])->toBeLessThanOrEqual(1000);
});

it('deterministically truncates large Knowledge results at the budget', function (): void {
    [$user, $enterprise] = retrievedKnowledgeActor();

    $items = [];
    foreach (range(1, 4) as $number) {
        $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => "Knowledge {$number}"]);
        $items[] = new KnowledgeRetrievalResultItem($item->getKey(), "Knowledge {$number}", summary: str_repeat('evidence ', 100), relevance: 0.9);
    }

    $provider = new RetrievedKnowledgeContextProvider(fakeKnowledgeRetrieval($items));

    $section = $provider->provide($user, $enterprise, [
        'retrieved_knowledge' => ['query' => 'evidence', 'limit' => 4, 'budget' => 100],
    ])[0];

    expect($section->data['items'])->toHaveCount(0)
        ->and($section->data['selection']['truncated'])->toBeTrue();
});

it('does not silently accept an unbounded or invalid retrieval budget', function (): void {
    [$user, $enterprise] = retrievedKnowledgeActor();
    $provider = new RetrievedKnowledgeContextProvider(fakeKnowledgeRetrieval([]));

    expect(fn () => $provider->provide($user, $enterprise, [
        'retrieved_knowledge' => ['query' => 'test', 'budget' => 0],
    ]))->toThrow(\InvalidArgumentException::class);

    expect(fn () => $provider->provide($user, $enterprise, [
        'retrieved_knowledge' => ['query' => 'test', 'limit' => 51],
    ]))->toThrow(\InvalidArgumentException::class);
});

it('requires an explicit retrieval query or objective', function (): void {
    [$user, $enterprise] = retrievedKnowledgeActor();
    $provider = new RetrievedKnowledgeContextProvider(fakeKnowledgeRetrieval([]));

    expect(fn () => $provider->provide($user, $enterprise, [
        'retrieved_knowledge' => ['budget' => 100],
    ]))->toThrow(\InvalidArgumentException::class);
});

it('integrates retrieved Knowledge through the canonical Agent context builder', function (): void {
    [$user, $enterprise] = retrievedKnowledgeActor();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Approval policy']);
    $version = KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'content' => 'Approval requires a manager.',
    ]);
    $record = new KnowledgeIndexRecord;
    $record->forceFill([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_version_id' => $version->getKey(),
        'unit_key' => 'chunk-0001',
        'representation_key' => hash('sha256', $item->getKey().':'.$version->getKey().':chunk-0001'),
        'status' => 'indexed',
    ])->save();

    $unit = new KnowledgeIndexUnit;
    $unit->forceFill([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_index_record_id' => $record->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_version_id' => $version->getKey(),
        'unit_key' => 'chunk-0001',
        'ordinal' => 1,
        'content' => 'Approval requires a manager.',
        'content_hash' => hash('sha256', 'Approval requires a manager.'),
    ])->save();

    $context = app(AgentContextBuilder::class)->build(
        $user,
        $enterprise,
        ['retrieved_knowledge'],
        ['retrieved_knowledge' => ['query' => 'approval manager', 'budget' => 800]],
    );

    expect($context->has('retrieved_knowledge'))->toBeTrue()
        ->and($context->section('retrieved_knowledge')?->data['items'])->not->toBeEmpty()
        ->and($context->metadata()['retrieved_knowledge']['scope']['enterprise_id'])->toBe($enterprise->getKey());
});