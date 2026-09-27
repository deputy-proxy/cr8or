<?php

use App\Enums\KnowledgeIndexStatus;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeIndexLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

function knowledgeIndexActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'version' => 1,
        'content' => 'Initial knowledge',
    ]);

    return [$user, $enterprise, $item];
}

it('creates a pending representation tied to the authoritative current version', function (): void {
    [$user, $enterprise, $item] = knowledgeIndexActor();

    $record = (new KnowledgeIndexLifecycleService)->requestIndex($user, $item, 'root', 'knowledge-193');

    expect($record->status)->toBe(KnowledgeIndexStatus::PENDING)
        ->and($record->enterprise_id)->toBe($enterprise->getKey())
        ->and($record->knowledge_item_id)->toBe($item->getKey())
        ->and($record->knowledge_version_id)->toBe($item->latestVersion->getKey())
        ->and($record->metadata)->toMatchArray(['correlation_id' => 'knowledge-193'])
        ->and($record->representation_key)->toHaveLength(64);
});

it('marks the prior representation stale when a new authoritative version is indexed', function (): void {
    [$user, $enterprise, $item] = knowledgeIndexActor();
    $service = new KnowledgeIndexLifecycleService;

    $first = $service->requestIndex($user, $item);
    $service->markIndexed($user, $first, 'fake-index', 'ref-1', 'hash-1');

    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'content' => 'Updated knowledge',
    ]);

    $second = $service->requestIndex($user, $item);

    expect($first->refresh()->status)->toBe(KnowledgeIndexStatus::STALE)
        ->and($second->status)->toBe(KnowledgeIndexStatus::PENDING)
        ->and($second->knowledge_version_id)->not->toBe($first->knowledge_version_id);
});

it('supports deterministic failure, reindex and removal lifecycle transitions', function (): void {
    [$user, , $item] = knowledgeIndexActor();
    $service = new KnowledgeIndexLifecycleService;

    $record = $service->requestIndex($user, $item);
    $service->markFailed($user, $record, 'provider_unavailable', 'Provider unavailable.');

    expect($record->refresh()->status)->toBe(KnowledgeIndexStatus::FAILED);

    $requeued = $service->requestIndex($user, $item);

    expect($requeued->getKey())->toBe($record->getKey())
        ->and($requeued->status)->toBe(KnowledgeIndexStatus::PENDING);

    $removed = $service->remove($user, $requeued);

    expect($removed->status)->toBe(KnowledgeIndexStatus::REMOVED);
});

it('does not allow stale or removed representations to be marked indexed', function (): void {
    [$user, , $item] = knowledgeIndexActor();
    $service = new KnowledgeIndexLifecycleService;

    $record = $service->requestIndex($user, $item);
    $service->invalidate($user, $item);

    expect(fn () => $service->markIndexed($user, $record, 'fake-index', 'ref-1', 'hash-1'))
        ->toThrow(LogicException::class);

    $record = $service->requestIndex($user, $item);
    $service->remove($user, $record);

    expect(fn () => $service->markIndexed($user, $record, 'fake-index', 'ref-1', 'hash-1'))
        ->toThrow(LogicException::class);
});

it('rejects unauthorized indexing lifecycle access before mutation', function (): void {
    [$user, , $item] = knowledgeIndexActor();
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreignEnterprise]);

    expect(fn () => (new KnowledgeIndexLifecycleService)->requestIndex($user, $foreignItem))
        ->toThrow(AuthorizationException::class)
        ->and(KnowledgeIndexRecord::query()->count())->toBe(0);
});

it('keeps the lifecycle model separate from authoritative Knowledge content', function (): void {
    expect(Schema::getColumnListing('knowledge_index_records'))
        ->not->toContain('content')
        ->toContain(
            'enterprise_id',
            'knowledge_item_id',
            'knowledge_version_id',
            'representation_key',
            'status',
        );
});