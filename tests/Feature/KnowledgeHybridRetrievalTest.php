<?php

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\Enterprise;
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
            return new KnowledgeRetrievalResult(
                'succeeded',
                $request->correlationId ?? 'generated',
                $this->items,
                count($this->items),
                ['mode' => $this->mode],
            );
        }
    };
}

it('combines lexical and semantic signals deterministically and deduplicates items', function (): void {
    [$user, $enterprise] = hybridActor();
    $lexicalItem = new KnowledgeRetrievalResultItem(1, 'Approval', relevance: 0.9);
    $semanticItem = new KnowledgeRetrievalResultItem(1, 'Approval', relevance: 0.7, version: ['id' => 5, 'version' => 2]);
    $other = new KnowledgeRetrievalResultItem(2, 'Budget', relevance: 0.6);

    $provider = new KnowledgeHybridRetrievalProvider(
        fakeRetrievalProvider([$lexicalItem, $other], 'lexical'),
        fakeRetrievalProvider([$semanticItem], 'semantic'),
    );

    $result = (new KnowledgeRetrievalService($provider))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'approval', mode: 'hybrid', limits: ['limit' => 5]),
    );

    expect($result->items)->toHaveCount(2)
        ->and($result->items[0]->knowledgeItemId)->toBe(1)
        ->and($result->items[0]->relevance)->toBe(0.8)
        ->and($result->items[0]->version)->toMatchArray(['version' => 2])
        ->and($result->metadata['mode'])->toBe('hybrid');
});

it('respects result bounds and stable tie-breaking', function (): void {
    [$user, $enterprise] = hybridActor();
    $provider = new KnowledgeHybridRetrievalProvider(
        fakeRetrievalProvider([
            new KnowledgeRetrievalResultItem(2, 'B', relevance: 0.5),
            new KnowledgeRetrievalResultItem(1, 'A', relevance: 0.5),
        ], 'lexical'),
        fakeRetrievalProvider([], 'semantic'),
    );

    $result = (new KnowledgeRetrievalService($provider))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'x', limits: ['limit' => 1]),
    );

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->knowledgeItemId)->toBe(1);
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