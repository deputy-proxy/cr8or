<?php

it('groups Enterprises by Portfolio Group and keeps ungrouped Enterprises visible', function () {
    $organization = \App\Models\Organization::factory()->create();
    $owner = \App\Models\User::factory()->create();
    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    $group = \App\Models\EnterpriseGroup::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Grouped portfolio',
    ]);
    \App\Models\Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_group_id' => $group->id,
        'name' => 'Enterprise in group',
    ]);
    \App\Models\Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Enterprise without group',
    ]);

    $this->actingAs($owner);

    \Livewire\Livewire::test(\App\Filament\Resources\Enterprises\Pages\ListEnterprises::class)
        ->assertStatus(200)
        ->assertSee('fi-ta-group-header-row', false)
        ->assertSee('Grouped portfolio')
        ->assertSee('Enterprise in group')
        ->assertSee('Ungrouped')
        ->assertSee('Enterprise without group');
});
