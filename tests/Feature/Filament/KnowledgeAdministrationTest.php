<?php

use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

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