<?php

use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use App\Filament\Resources\KnowledgeItems\Pages\ListKnowledgeItems;
use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('scopes knowledge administration to the authenticated organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $visible = KnowledgeItem::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $organization])]);
    $hidden = KnowledgeItem::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization])]);

    $this->actingAs($user);

    expect(KnowledgeItemResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($visible->id)
        ->not->toContain($hidden->id);
});

it('allows organization owners to create knowledge administration records', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Enterprise::factory()->create(['organization_id' => $organization]);

    $this->actingAs($owner);

    expect(KnowledgeItemResource::canViewAny())->toBeTrue()
        ->and(KnowledgeItemResource::canCreate())->toBeTrue();
});

it('groups knowledge items by enterprise, filters by authorized enterprise, and displays every non-summary column', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);

    $firstEnterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'Alpha Enterprise']);
    $secondEnterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'Beta Enterprise']);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization, 'name' => 'Foreign Enterprise']);

    $source = KnowledgeSource::factory()->create(['enterprise_id' => $firstEnterprise, 'name' => 'Internal source']);
    $document = KnowledgeDocument::factory()->create(['enterprise_id' => $firstEnterprise, 'knowledge_source_id' => $source, 'title' => 'Reference document']);
    $context = KnowledgeContext::factory()->create(['enterprise_id' => $firstEnterprise, 'name' => 'Product context']);

    $firstItem = KnowledgeItem::factory()->create([
        'enterprise_id' => $firstEnterprise,
        'knowledge_source_id' => $source,
        'knowledge_document_id' => $document,
        'knowledge_context_id' => $context,
        'title' => 'First knowledge item',
    ]);
    $secondItem = KnowledgeItem::factory()->create(['enterprise_id' => $secondEnterprise, 'title' => 'Second knowledge item']);
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreignEnterprise, 'title' => 'Foreign knowledge item']);

    $this->actingAs($owner);

    Livewire::test(ListKnowledgeItems::class)
        ->assertSet('tableGrouping', 'enterprise_id:asc')
        ->assertCanSeeTableRecords([$firstItem, $secondItem])
        ->assertCanNotSeeTableRecords([$foreignItem])
        ->assertTableColumnExists('id')
        ->assertTableColumnExists('enterprise.name')
        ->assertTableColumnExists('source.name')
        ->assertTableColumnExists('document.title')
        ->assertTableColumnExists('context.name')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('type')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at')
        ->filterTable('enterprise_id', $secondEnterprise->id)
        ->assertCanSeeTableRecords([$secondItem])
        ->assertCanNotSeeTableRecords([$firstItem, $foreignItem]);
});

it('filters knowledge items by document, type, and context', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $firstDocument = KnowledgeDocument::factory()->create(['enterprise_id' => $enterprise, 'title' => 'First document']);
    $secondDocument = KnowledgeDocument::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Second document']);
    $firstContext = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise, 'name' => 'First context']);
    $secondContext = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Second context']);

    $decision = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_document_id' => $firstDocument,
        'knowledge_context_id' => $firstContext,
        'type' => 'decision',
    ]);
    $fact = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_document_id' => $secondDocument,
        'knowledge_context_id' => $secondContext,
        'type' => 'fact',
    ]);

    $this->actingAs($owner);

    Livewire::test(ListKnowledgeItems::class)
        ->assertCanSeeTableRecords([$decision, $fact])
        ->filterTable('knowledge_document_id', $firstDocument->id)
        ->assertCanSeeTableRecords([$decision])
        ->assertCanNotSeeTableRecords([$fact])
        ->removeTableFilter('knowledge_document_id')
        ->filterTable('type', 'fact')
        ->assertCanSeeTableRecords([$fact])
        ->assertCanNotSeeTableRecords([$decision])
        ->removeTableFilter('type')
        ->filterTable('knowledge_context_id', $secondContext->id)
        ->assertCanSeeTableRecords([$fact])
        ->assertCanNotSeeTableRecords([$decision]);
});

it('allows owners to bulk delete selected knowledge items', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $first = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    $second = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    $untouched = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);

    $this->actingAs($owner);

    Livewire::test(ListKnowledgeItems::class)
        ->callTableBulkAction('delete', [$first, $second])
        ->assertHasNoErrors();

    expect(KnowledgeItem::query()->whereKey($first->getKey())->exists())->toBeFalse()
        ->and(KnowledgeItem::query()->whereKey($second->getKey())->exists())->toBeFalse()
        ->and(KnowledgeItem::query()->whereKey($untouched->getKey())->exists())->toBeTrue();
});

it('rejects bulk deletion by organization admins who do not have knowledge item delete permission', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    Membership::factory()->admin()->create(['user_id' => $admin, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $first = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    $second = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);

    $this->actingAs($admin);

    Livewire::test(ListKnowledgeItems::class)
        ->callTableBulkAction('delete', [$first, $second])
        ->assertForbidden();

    expect(KnowledgeItem::query()->whereKey($first->getKey())->exists())->toBeTrue()
        ->and(KnowledgeItem::query()->whereKey($second->getKey())->exists())->toBeTrue();
});
