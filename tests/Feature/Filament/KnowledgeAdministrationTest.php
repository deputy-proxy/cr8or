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
