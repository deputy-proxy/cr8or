<?php

use App\Data\KnowledgeIndexingResult;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeContentNormalizationService;
use App\Services\KnowledgeIndexLifecycleService;
use App\Services\KnowledgeIndexingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

function indexingActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'content' => 'Approval policy content.',
    ]);

    return [$user, $enterprise, $item];
}

it('indexes normalized Knowledge through one authorized idempotent boundary', function (): void {
    [$user, $enterprise, $item] = indexingActor();
    $service = new KnowledgeIndexingService(new KnowledgeContentNormalizationService, new KnowledgeIndexLifecycleService);

    $first = $service->indexItem($user, $item, 'corr-195');
    $second = $service->indexItem($user, $item, 'corr-195');

    expect($first)->toBeInstanceOf(KnowledgeIndexingResult::class)
        ->and($first->indexedUnits)->toBe(1)
        ->and($second->indexedUnits)->toBe(1)
        ->and(KnowledgeIndexRecord::query()->count())->toBe(1)
        ->and(KnowledgeIndexUnit::query()->count())->toBe(1)
        ->and(KnowledgeIndexRecord::first()->enterprise_id)->toBe($enterprise->getKey())
        ->and(KnowledgeIndexRecord::first()->status->value)->toBe('indexed');
});

it('reindexes a changed version and leaves the previous representation stale', function (): void {
    [$user, , $item] = indexingActor();
    $service = new KnowledgeIndexingService(new KnowledgeContentNormalizationService, new KnowledgeIndexLifecycleService);

    $service->indexItem($user, $item);
    $first = KnowledgeIndexRecord::query()->first();

    KnowledgeVersion::factory()->create([
        'enterprise_id' => $item->enterprise_id,
        'knowledge_item_id' => $item,
        'content' => 'Updated approval policy content.',
    ]);

    $service->indexItem($user, $item);
    $second = KnowledgeIndexRecord::query()->where('status', 'indexed')->first();

    expect($first->refresh()->status->value)->toBe('stale')
        ->and($second)->not->toBeNull()
        ->and($second->knowledge_version_id)->not->toBe($first->knowledge_version_id);
});

it('rejects cross-Enterprise indexing before writing searchable content', function (): void {
    [$user] = indexingActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreignEnterprise]);

    $service = new KnowledgeIndexingService(new KnowledgeContentNormalizationService, new KnowledgeIndexLifecycleService);

    expect(fn () => $service->indexItem($user, $foreignItem))
        ->toThrow(AuthorizationException::class)
        ->and(KnowledgeIndexUnit::query()->count())->toBe(0);
});

it('keeps indexed units organization-scoped and content-bearing only in the representation layer', function (): void {
    expect(Schema::getColumnListing('knowledge_index_units'))
        ->toContain('enterprise_id', 'knowledge_index_record_id', 'knowledge_item_id', 'knowledge_version_id', 'content')
        ->and(Schema::getColumnListing('knowledge_items'))->not->toContain('content_hash');
});