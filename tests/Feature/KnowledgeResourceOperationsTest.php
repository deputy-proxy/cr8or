<?php

use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Operations\ArchiveKnowledgeUnit;
use App\Operations\CreateKnowledgeIndex;
use App\Operations\CreateKnowledgeUnit;
use App\Operations\GetKnowledgeIndex;
use App\Operations\GetKnowledgeUnit;
use App\Operations\ListKnowledgeIndexes;
use App\Operations\ListKnowledgeUnits;
use App\Operations\UpdateKnowledgeIndex;
use App\Operations\UpdateKnowledgeUnit;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

function knowledgeResourceActor(): array
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
        'content' => "# Approval\n\nApproval threshold is 1000 EUR.\n\n## Escalation\n\nHigher amounts require director approval.",
    ]);

    return [$user, $enterprise, $item];
}

it('creates, inspects and lists a complete Knowledge index and its units', function (): void {
    [$user, $enterprise, $item] = knowledgeResourceActor();

    $created = app(CreateKnowledgeIndex::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'correlation_id' => 'knowledge-resource-1',
    ]);

    expect($created['knowledge_item_id'])->toBe($item->getKey())
        ->and($created['indexed_units'])->toBe(2)
        ->and($created['failed_units'])->toBe(0)
        ->and($created['indexes'])->toHaveCount(2);

    $indexes = app(ListKnowledgeIndexes::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 50,
    ]);
    $units = app(ListKnowledgeUnits::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 50,
    ]);

    expect($indexes['items'])->toHaveCount(2)
        ->and($units['items'])->toHaveCount(2)
        ->and($units['items'][0]['content'])->not->toBeEmpty();

    $index = KnowledgeIndexRecord::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();
    $unit = KnowledgeIndexUnit::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();

    expect(app(GetKnowledgeIndex::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_index_id' => $index->getKey(),
    ]))->toMatchArray(['id' => $index->getKey(), 'knowledge_item_id' => $item->getKey()]);

    expect(app(GetKnowledgeUnit::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_unit_id' => $unit->getKey(),
    ]))->toMatchArray(['id' => $unit->getKey(), 'knowledge_item_id' => $item->getKey()]);
});

it('keeps update operations derived from authoritative Knowledge and idempotent', function (): void {
    [$user, $enterprise, $item] = knowledgeResourceActor();

    app(CreateKnowledgeIndex::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
    ]);

    $index = KnowledgeIndexRecord::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();
    $unit = KnowledgeIndexUnit::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();

    $updatedIndex = app(UpdateKnowledgeIndex::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_index_id' => $index->getKey(),
        'correlation_id' => 'knowledge-resource-2',
    ]);

    $updatedUnit = app(UpdateKnowledgeUnit::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_unit_id' => $unit->getKey(),
    ]);

    expect($updatedIndex['unit_key'])->toBe($index->unit_key)
        ->and($updatedIndex['status'])->toBe('indexed')
        ->and($updatedUnit['unit_key'])->toBe($unit->unit_key)
        ->and(KnowledgeIndexRecord::query()->where('knowledge_item_id', $item->getKey())->where('status', 'indexed')->count())->toBe(2);
});

it('archives derived units without deleting authoritative Knowledge', function (): void {
    [$user, $enterprise, $item] = knowledgeResourceActor();

    app(CreateKnowledgeIndex::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
    ]);

    $unit = KnowledgeIndexUnit::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();

    $archived = app(ArchiveKnowledgeUnit::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_unit_id' => $unit->getKey(),
        'reason' => 'source retired',
    ]);

    expect($archived['record_status'])->toBe('removed')
        ->and(KnowledgeItem::query()->whereKey($item->getKey())->exists())->toBeTrue()
        ->and(KnowledgeIndexUnit::query()->whereKey($unit->getKey())->exists())->toBeTrue();

    $listed = app(ListKnowledgeUnits::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 50,
    ]);

    expect($listed['items'])->toHaveCount(1);
});

it('creates a requested unit from the canonical indexer', function (): void {
    [$user, $enterprise, $item] = knowledgeResourceActor();

    $unit = app(CreateKnowledgeUnit::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'unit_key' => 'chunk-0002',
    ]);

    expect($unit['unit_key'])->toBe('chunk-0002')
        ->and($unit['knowledge_item_id'])->toBe($item->getKey())
        ->and($unit['record_status'])->toBe('indexed');
});

it('rejects cross-enterprise Knowledge resources before access is granted', function (): void {
    [$user, $enterprise] = knowledgeResourceActor();
    $foreign = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreign]);

    expect(fn () => app(CreateKnowledgeIndex::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $foreignItem->getKey(),
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(ListKnowledgeIndexes::class)->execute($user, [
        'enterprise_id' => $foreign->getKey(),
    ]))->toThrow(AuthorizationException::class);
});