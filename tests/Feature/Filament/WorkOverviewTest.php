<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('scopes Overview headline counts to the selected enterprise', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'A visible enterprise']);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'B second enterprise']);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization, 'name' => 'Hidden enterprise']);

    Goal::factory()->create(['enterprise_id' => $enterprise]);
    Goal::factory()->count(2)->create(['enterprise_id' => $otherEnterprise]);
    Goal::factory()->count(3)->create(['enterprise_id' => $hiddenEnterprise]);
    Project::factory()->create(['enterprise_id' => $enterprise, 'status' => 'active']);
    Task::factory()->create(['enterprise_id' => $enterprise, 'status' => 'todo']);

    $this->actingAs($user);
    $page = app(WorkOverview::class);
    $page->mount();

    expect($page->selectedEnterpriseId)->toBe((int) $enterprise->id)
        ->and(collect($page->overview)->keyBy('label')['Goals']['count'])->toBe(1);

    $page->selectEnterprise((int) $otherEnterprise->id);
    expect($page->selectedEnterpriseId)->toBe((int) $otherEnterprise->id)
        ->and(collect($page->overview)->keyBy('label')['Goals']['count'])->toBe(2);
});

it('rejects selecting an enterprise outside the current user scope', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $this->actingAs($user);
    $page = app(WorkOverview::class);
    $page->mount();

    expect(fn () => $page->selectEnterprise((int) $hiddenEnterprise->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($page->selectedEnterpriseId)->toBe((int) $enterprise->id);
});

it('denies Overview access when the user has no organization membership', function () {
    $this->actingAs(User::factory()->create());

    expect(WorkOverview::canAccess())->toBeFalse();
});

it('renders the four dashboard rows and explicitly marks historical chart data unavailable', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'Dashboard enterprise']);

    $this->actingAs($user);

    \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('Overview')
        ->assertSee('Strategies')
        ->assertSee('Goals')
        ->assertSee('Objectives')
        ->assertSee('Initiatives')
        ->assertSee('KPIs')
        ->assertSee('Projects')
        ->assertSee('Milestones')
        ->assertSee('Daily activity')
        ->assertSee('History unavailable')
        ->assertSee('Recent activity')
        ->assertSee('Late tasks')
        ->assertSee('Tasks due this week')
        ->assertSee('Tasks due next week');
});