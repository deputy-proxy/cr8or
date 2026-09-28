<?php

use App\Data\CapabilityInvocationRequest;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\CapabilityInvocationService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

function knowledgeCapabilityActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    return [$user, $enterprise];
}

it('executes the complete Knowledge lifecycle through Capabilities without MCP', function (): void {
    [$user, $enterprise] = knowledgeCapabilityActor();

    $created = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.item.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'title' => 'Approval Policy',
            'type' => 'policy',
            'summary' => 'Approval thresholds for operating spend.',
            'content' => 'Approval threshold is 1000 EUR. Amounts above the threshold require director approval.',
        ],
        correlationId: 'knowledge-capability-1',
        idempotencyKey: 'knowledge-item-1',
    ));

    expect($created['status'])->toBe('executed')
        ->and($created['provenance'])->toMatchArray([
            'capability' => 'knowledge.item.create',
            'operation' => App\Operations\CreateKnowledgeItem::class,
            'enterprise_id' => $enterprise->getKey(),
            'correlation_id' => 'knowledge-capability-1',
        ]);

    $itemId = $created['result']['id'];
    $versionId = $created['result']['version']['id'];

    expect(KnowledgeItem::query()->whereKey($itemId)->where('enterprise_id', $enterprise->getKey())->exists())->toBeTrue()
        ->and(KnowledgeVersion::query()->whereKey($versionId)->where('knowledge_item_id', $itemId)->where('content', 'like', '%1000 EUR%')->exists())->toBeTrue();

    $indexed = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.index.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: ['knowledge_item_id' => $itemId],
        correlationId: 'knowledge-capability-2',
        idempotencyKey: 'knowledge-index-1',
    ));

    expect($indexed['status'])->toBe('executed')
        ->and($indexed['result']['knowledge_item_id'])->toBe($itemId)
        ->and($indexed['result']['indexed_units'])->toBeGreaterThan(0)
        ->and(KnowledgeIndexRecord::query()->where('knowledge_item_id', $itemId)->where('status', 'indexed')->exists())->toBeTrue()
        ->and(KnowledgeIndexUnit::query()->where('knowledge_item_id', $itemId)->exists())->toBeTrue();

    $unit = KnowledgeIndexUnit::query()->where('knowledge_item_id', $itemId)->firstOrFail();

    $unitResult = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.unit.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'knowledge_item_id' => $itemId,
            'unit_key' => $unit->unit_key,
        ],
        correlationId: 'knowledge-capability-3',
        idempotencyKey: 'knowledge-unit-1',
    ));

    expect($unitResult['status'])->toBe('executed')
        ->and($unitResult['result']['knowledge_item_id'])->toBe($itemId)
        ->and($unitResult['result']['record_status'])->toBe('indexed');

    $retrieved = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.retrieve',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'query' => 'approval threshold',
            'mode' => 'lexical',
            'limit' => 5,
        ],
        correlationId: 'knowledge-capability-4',
        idempotencyKey: 'knowledge-retrieve-1',
    ));

    expect($retrieved['status'])->toBe('executed')
        ->and($retrieved['result']['items'])->not->toBeEmpty()
        ->and(collect($retrieved['result']['items'])->pluck('knowledge_item_id'))->toContain($itemId)
        ->and($retrieved['result']['metadata']['provenance_normalized'])->toBeTrue();
});

it('keeps repeated Knowledge indexing derived and idempotent', function (): void {
    [$user, $enterprise] = knowledgeCapabilityActor();

    $created = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.item.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'title' => 'Repeated Index Policy',
            'content' => 'Repeated indexing must rebuild the derived representation without duplicating authoritative Knowledge.',
        ],
    ));

    $itemId = $created['result']['id'];

    $first = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.index.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: ['knowledge_item_id' => $itemId],
        idempotencyKey: 'knowledge-index-repeat',
    ));

    $countAfterFirst = KnowledgeIndexRecord::query()->where('knowledge_item_id', $itemId)->count();

    $second = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.index.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: ['knowledge_item_id' => $itemId],
        idempotencyKey: 'knowledge-index-repeat',
    ));

    expect($first['status'])->toBe('executed')
        ->and($second['status'])->toBe('executed')
        ->and(KnowledgeItem::query()->whereKey($itemId)->count())->toBe(1)
        ->and(KnowledgeIndexRecord::query()->where('knowledge_item_id', $itemId)->count())->toBe($countAfterFirst)
        ->and($second['result']['indexed_units'])->toBeGreaterThan(0);
});

it('rejects Knowledge Capability execution across Enterprise boundaries', function (): void {
    [$user] = knowledgeCapabilityActor();
    $foreign = Enterprise::factory()->create();

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.item.create',
        actor: $user,
        enterprise: $foreign,
        inputPayload: [
            'title' => 'Must not persist',
            'content' => 'Unauthorized Knowledge must never be created.',
        ],
    )))->toThrow(AuthorizationException::class);

    expect(KnowledgeItem::query()->where('enterprise_id', $foreign->getKey())->where('title', 'Must not persist')->exists())->toBeFalse();
});

it('rejects indexing a Knowledge Item from another Enterprise', function (): void {
    [$user, $enterprise] = knowledgeCapabilityActor();
    $foreign = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreign]);

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'knowledge.index.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: ['knowledge_item_id' => $foreignItem->getKey()],
    )))->toThrow(InvalidArgumentException::class);
});
