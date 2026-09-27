<?php

use App\Models\Enterprise;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeContentNormalizationService;
use Illuminate\Auth\Access\AuthorizationException;

function knowledgeNormalizationActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    return [$user, $enterprise];
}

it('produces deterministic version-aware units with structural metadata', function (): void {
    [$user, $enterprise] = knowledgeNormalizationActor();
    $document = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise,
        'content' => "# Operations\n\nApproval rules apply.\n\n## Escalation\n\nEscalate above the threshold.",
    ]);
    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_document_id' => $document,
    ]);
    $reference = KnowledgeReference::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_document_id' => $document,
        'knowledge_item_id' => $item,
        'locator' => 'section-2',
    ]);

    $service = new KnowledgeContentNormalizationService;
    $first = $service->normalizeDocument($user, $document);
    $second = $service->normalizeDocument($user, $document);

    expect($first)->toEqual($second)
        ->and($first)->toHaveCount(2)
        ->and($first[0]->headingPath)->toBe(['Operations'])
        ->and($first[1]->headingPath)->toBe(['Operations', 'Escalation'])
        ->and($first[0]->references[0])->toMatchArray(['id' => $reference->getKey(), 'locator' => 'section-2'])
        ->and($first[0]->enterpriseId)->toBe($enterprise->getKey());
});

it('returns no units for empty content', function (): void {
    [$user, $enterprise] = knowledgeNormalizationActor();
    $document = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise,
        'content' => " \n\n ",
    ]);

    expect((new KnowledgeContentNormalizationService)->normalizeDocument($user, $document))
        ->toBe([]);
});

it('bounds large content without exceeding the configured unit size', function (): void {
    [$user, $enterprise] = knowledgeNormalizationActor();
    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise,
        'summary' => str_repeat('Knowledge ', 100),
    ]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'version' => 1,
        'content' => str_repeat('Deterministic searchable content ', 250),
    ]);

    $units = (new KnowledgeContentNormalizationService(200))->normalizeItem($user, $item);

    expect($units)->not->toBe([])
        ->and(max(array_map(static fn ($unit): int => mb_strlen($unit->content), $units)))->toBeLessThanOrEqual(200)
        ->and($units[0]->versionId)->toBe($item->latestVersion->getKey());
});

it('uses a changed version identity when authoritative item content changes', function (): void {
    [$user, $enterprise] = knowledgeNormalizationActor();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'summary' => 'Fallback']);
    $firstVersion = KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'version' => 1,
        'content' => 'Version one',
    ]);

    $first = (new KnowledgeContentNormalizationService)->normalizeItem($user, $item);

    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'content' => 'Version two',
    ]);

    $second = (new KnowledgeContentNormalizationService)->normalizeItem($user, $item);

    expect($first[0]->versionId)->toBe($firstVersion->getKey())
        ->and($second[0]->versionId)->not->toBe($first[0]->versionId)
        ->and($second[0]->content)->toBe('Version two');
});

it('denies cross-Enterprise normalization before reading content', function (): void {
    [$user] = knowledgeNormalizationActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $document = KnowledgeDocument::factory()->create(['enterprise_id' => $foreignEnterprise]);

    expect(fn () => (new KnowledgeContentNormalizationService)->normalizeDocument($user, $document))
        ->toThrow(AuthorizationException::class);
});