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
use App\Services\KnowledgeRetrievalObservability;
use App\Services\KnowledgeRetrievalService;
use Illuminate\Support\Facades\Log;

function observabilityActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, Enterprise::factory()->create(['organization_id' => $organization])];
}

it('records structural retrieval diagnostics without logging retrieved content', function (): void {
    [$user, $enterprise] = observabilityActor();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Secret title']);
    Log::spy();

    $provider = new class($item) implements KnowledgeRetrievalProvider
    {
        public function __construct(private KnowledgeItem $item) {}

        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            return new KnowledgeRetrievalResult(
                'succeeded',
                $request->correlationId ?? 'obs-1',
                [new KnowledgeRetrievalResultItem($this->item->getKey(), 'Secret title', summary: 'Sensitive body')],
                3,
                ['mode' => 'lexical', 'term_count' => 2, 'secret_content' => 'Sensitive body'],
            );
        }
    };

    $result = (new KnowledgeRetrievalService($provider, new \App\Services\KnowledgeRetrievalProvenanceService, new KnowledgeRetrievalObservability))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'secret', mode: 'lexical', correlationId: 'obs-1'),
    );

    expect($result->correlationId)->toBe('obs-1');

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'CR8OR Knowledge retrieval completed.'
            && $context['correlation_id'] === 'obs-1'
            && $context['candidate_count'] === 3
            && $context['result_count'] === 1
            && ! array_key_exists('secret_content', $context['provider_metadata'])
        );
});

it('records retrieval failures separately from downstream Agent failures', function (): void {
    [$user, $enterprise] = observabilityActor();
    Log::spy();

    $provider = new class implements KnowledgeRetrievalProvider
    {
        public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
        {
            throw new RuntimeException('provider unavailable');
        }
    };

    expect(fn () => (new KnowledgeRetrievalService($provider, new \App\Services\KnowledgeRetrievalProvenanceService, new KnowledgeRetrievalObservability))->retrieve(
        new KnowledgeRetrievalRequest(actor: $user, enterprise: $enterprise, query: 'anything', correlationId: 'obs-failure'),
    ))->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'CR8OR Knowledge retrieval failed.'
            && $context['correlation_id'] === 'obs-failure'
            && $context['error_class'] === RuntimeException::class
        );
});