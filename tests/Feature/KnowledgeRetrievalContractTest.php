<?php

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeRetrievalService;
use Illuminate\Auth\Access\AuthorizationException;

it('retrieves authorized knowledge through the provider-neutral contract', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    $provider = new class implements KnowledgeRetrievalProvider
    {
        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            return new KnowledgeRetrievalResult(
                status: 'succeeded',
                correlationId: $request->correlationId ?? 'generated-correlation',
                candidateCount: 1,
                items: [
                    new KnowledgeRetrievalResultItem(
                        knowledgeItemId: 42,
                        title: 'Approval threshold',
                        summary: 'Approval is required above the configured threshold.',
                        relevance: 0.92,
                        source: ['id' => 1, 'name' => 'Operations handbook'],
                        document: ['id' => 2, 'title' => 'Operations handbook'],
                        context: ['id' => 3, 'name' => 'Operations'],
                        references: [['id' => 4, 'type' => 'source', 'locator' => 'section-4']],
                        version: ['id' => 5, 'version' => 2],
                    ),
                ],
            );
        }
    };

    $request = new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'approval threshold',
        objective: 'Determine the applicable approval rule.',
        mode: 'default',
        limits: ['limit' => 10],
        relevance: ['minimum_relevance' => 0.5],
        correlationId: 'knowledge-192',
    );

    $result = (new KnowledgeRetrievalService($provider))->retrieve($request);

    expect($result->succeeded())->toBeTrue()
        ->and($result->items)->toHaveCount(1)
        ->and($result->items[0]->source)->toMatchArray(['id' => 1])
        ->and($result->items[0]->document)->toMatchArray(['id' => 2])
        ->and($result->items[0]->context)->toMatchArray(['id' => 3])
        ->and($result->items[0]->references[0])->toMatchArray(['locator' => 'section-4'])
        ->and($result->items[0]->version)->toMatchArray(['version' => 2]);
});

it('supports deterministic empty retrieval results without coupling the contract to storage', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    $provider = new class implements KnowledgeRetrievalProvider
    {
        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            return new KnowledgeRetrievalResult(
                status: 'succeeded',
                correlationId: $request->correlationId ?? 'generated-correlation',
            );
        }
    };

    $result = (new KnowledgeRetrievalService($provider))->retrieve(new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'does not exist',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->empty())->toBeTrue()
        ->and($result->candidateCount)->toBe(0);
});

it('rejects unauthorized Enterprise retrieval before invoking the provider', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    $provider = new class implements KnowledgeRetrievalProvider
    {
        public bool $called = false;

        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            $this->called = true;

            return new KnowledgeRetrievalResult(
                status: 'succeeded',
                correlationId: $request->correlationId ?? 'generated-correlation',
            );
        }
    };

    expect(fn () => (new KnowledgeRetrievalService($provider))->retrieve(new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'secret',
    )))->toThrow(AuthorizationException::class)
        ->and($provider->called)->toBeFalse();
});

it('requires retrieval intent and validates bounded request inputs', function (): void {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    expect(fn () => new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new KnowledgeRetrievalRequest(
        actor: $user,
        enterprise: $enterprise,
        query: 'test',
        limits: ['limit' => 0],
    ))->toThrow(InvalidArgumentException::class);
});