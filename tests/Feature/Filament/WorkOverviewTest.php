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

it('renders the four dashboard rows and the lifecycle history charts', function () {
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
        ->assertSee('Last 14 complete days')
        ->assertSee('data-lifecycle-history-chart', false)
        ->assertSee('GitHub transition coverage is not yet verifiable.')
        ->assertDontSee('History unavailable')
        ->assertSee('Recent activity')
        ->assertSee('Late tasks')
        ->assertSee('Tasks due this week')
        ->assertSee('Tasks due next week');
});

it('uses calendar dates and Monday week boundaries for actionable task panels', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => Organization::factory()->create()]);

    $reference = now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addDays(2)->setTime(12, 0);
    \Illuminate\Support\Carbon::setTestNow($reference);

    try {
        Task::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Late actionable task', 'status' => 'todo', 'due_at' => $reference->copy()->subDay()]);
        Task::factory()->create(['enterprise_id' => $enterprise, 'name' => 'This week task', 'status' => 'in_progress', 'due_at' => $reference->copy()->addDay()]);
        Task::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Next week task', 'status' => 'todo', 'due_at' => $reference->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addWeek()->addDay()]);
        Task::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Completed historical task', 'status' => 'done', 'due_at' => $reference->copy()->subDay()]);
        Task::factory()->create(['enterprise_id' => $otherEnterprise, 'name' => 'Other enterprise task', 'status' => 'todo', 'due_at' => $reference->copy()->subDay()]);

        $this->actingAs($user);
        $data = app(\App\Services\Dashboard\OverviewDashboardData::class)->forEnterprise($enterprise);
        $panels = collect($data['activityPanels'])->keyBy('label');

        expect($data['lifecycleHistory']['goals']['available'])->toBeFalse()
            ->and(collect($panels['Late tasks']['items'])->pluck('title'))->toContain('Late actionable task')->not->toContain('Completed historical task', 'Other enterprise task')
            ->and(collect($panels['Tasks due this week']['items'])->pluck('title'))->toContain('This week task')
            ->and(collect($panels['Tasks due next week']['items'])->pluck('title'))->toContain('Next week task');
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});