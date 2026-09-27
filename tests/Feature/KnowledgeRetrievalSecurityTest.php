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
use App\Services\KnowledgeRetrievalService;
use Illuminate\Auth\Access\AuthorizationException;

function securityActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, Enterprise::factory()->create(['organization_id' => $organization])];
}

it('rejects provider results that point to another Enterprise', function (): void {
    [$user, $enterprise] = securityActor();
    $foreign = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreign]);

    $provider = new class($foreignItem) implements KnowledgeRetrievalProvider
    {
        public function __construct(private KnowledgeItem $item) {}

        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            return new KnowledgeRetrievalResult(
                'succeeded',
                $request->correlationId ?? 'security',
                [new KnowledgeRetrievalResultItem($this->item->getKey(), 'Foreign')],
                1,
            );
        }
    };

    expect(fn () => (new KnowledgeRetrievalService($provider, new \App\Services\KnowledgeRetrievalProvenanceService))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'secret'),
    ))->toThrow(LogicException::class);
});

it('rejects unauthorized Enterprise access before invoking the provider', function (): void {
    [$user] = securityActor();
    $foreign = Enterprise::factory()->create();
    $called = false;

    $provider = new class($called) implements KnowledgeRetrievalProvider
    {
        public function __construct(private bool &$called) {}

        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            $this->called = true;

            return new KnowledgeRetrievalResult('succeeded', 'security');
        }
    };

    expect(fn () => (new KnowledgeRetrievalService($provider, new \App\Services\KnowledgeRetrievalProvenanceService))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $foreign, query: 'secret'),
    ))->toThrow(AuthorizationException::class)
        ->and($called)->toBeFalse();
});