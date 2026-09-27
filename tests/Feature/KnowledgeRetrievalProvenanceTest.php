<?php

use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeRetrievalProvenanceService;
use Illuminate\Auth\Access\AuthorizationException;

function provenanceActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, Enterprise::factory()->create(['organization_id' => $organization])];
}

it('normalizes retrieval provenance to authoritative Knowledge records', function (): void {
    [$user, $enterprise] = provenanceActor();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Approval']);
    $version = KnowledgeVersion::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'version' => 3, 'content' => 'Approval']);
    $reference = KnowledgeReference::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'locator' => 'section-3']);

    $result = (new KnowledgeRetrievalProvenanceService)->normalize(
        $user,
        $enterprise,
        new KnowledgeRetrievalResult('succeeded', 'corr-199', [
            new KnowledgeRetrievalResultItem($item->getKey(), 'Provider title'),
        ]),
    );

    expect($result->items[0]->title)->toBe('Approval')
        ->and($result->items[0]->version)->toMatchArray(['id' => $version->getKey(), 'version' => 3])
        ->and($result->items[0]->references[0])->toMatchArray(['id' => $reference->getKey()])
        ->and($result->items[0]->metadata['provenance_current'])->toBeTrue()
        ->and($result->metadata['provenance_normalized'])->toBeTrue();
});

it('rejects a provider result pointing to another Enterprise', function (): void {
    [$user, $enterprise] = provenanceActor();
    $foreign = Enterprise::factory()->create();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $foreign]);

    expect(fn () => (new KnowledgeRetrievalProvenanceService)->normalize(
        $user,
        $enterprise,
        new KnowledgeRetrievalResult('succeeded', 'corr-199', [
            new KnowledgeRetrievalResultItem($item->getKey(), 'Secret'),
        ]),
    ))->toThrow(LogicException::class);
});

it('enforces Knowledge authorization while normalizing provenance', function (): void {
    [$user, $enterprise] = provenanceActor();
    $foreign = Enterprise::factory()->create();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $foreign]);

    expect(fn () => (new KnowledgeRetrievalProvenanceService)->normalize(
        $user,
        $foreign,
        new KnowledgeRetrievalResult('succeeded', 'corr-199', [
            new KnowledgeRetrievalResultItem($item->getKey(), 'Secret'),
        ]),
    ))->toThrow(AuthorizationException::class);
});