<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

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

    $data = app(\App\Services\Dashboard\OverviewDashboardData::class);
    expect($page->selectedEnterpriseId)->toBe((int) $enterprise->id)
        ->and(collect($data->summaryCards($enterprise))->keyBy('label')['Goals']['count'])->toBe(1);

    $page->selectEnterprise((int) $otherEnterprise->id);
    expect($page->selectedEnterpriseId)->toBe((int) $otherEnterprise->id)
        ->and(collect($data->summaryCards($otherEnterprise))->keyBy('label')['Goals']['count'])->toBe(2);
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

it('renders the live query-driven dashboard and a truthful activity empty state', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'Dashboard enterprise']);
    Event::query()->delete();
    expect(Schema::hasTable('lifecycle_snapshots'))->toBeFalse();

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
        ->assertSee('Recorded activity · Last 14 days')
        ->assertSee('No activity has been recorded for this enterprise in the last 14 days.')
        ->assertSee('refreshes every 30 seconds')
        ->assertSee('wire:poll.30s.visible="refreshDashboard"', false)
        ->assertDontSee('Collecting history')
        ->assertDontSee('Waiting for complete start/end snapshots')
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
        Event::query()->delete();

        $this->actingAs($user);
        $data = app(\App\Services\Dashboard\OverviewDashboardData::class)->forEnterprise($enterprise);
        $panels = collect($data['activityPanels'])->keyBy('label');

        expect($data['activityAvailable'])->toBeTrue()
            ->and($data['activityDays'])->toBe([])
            ->and(collect($panels['Late tasks']['items'])->pluck('title'))->toContain('Late actionable task')->not->toContain('Completed historical task', 'Other enterprise task')
            ->and(collect($panels['Tasks due this week']['items'])->pluck('title'))->toContain('This week task')
            ->and(collect($panels['Tasks due next week']['items'])->pluck('title'))->toContain('Next week task');
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});

it('shows only persisted activity for the selected enterprise grouped by application date', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $unaffiliatedActor = User::factory()->create();
    Event::query()->delete();

    Event::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $user->id,
        'event_type' => 'strategy.created',
        'description' => 'Strategy Alpha created',
        'occurred_at' => now()->subHours(2),
    ]);
    Event::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $otherEnterprise->id,
        'event_type' => 'strategy.created',
        'description' => 'Foreign strategy activity',
        'occurred_at' => now()->subHour(),
    ]);
    Event::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $unaffiliatedActor->id,
        'event_type' => 'task.updated',
        'description' => 'Activity from a non-member actor',
        'occurred_at' => now()->subHour(),
    ]);

    $this->actingAs($user);
    $data = app(\App\Services\Dashboard\OverviewDashboardData::class)->forEnterprise($enterprise);

    expect($data['activityAvailable'])->toBeTrue()
        ->and($data['activityDays'])->toHaveCount(1)
        ->and(collect($data['activityDays'])->flatMap(fn (array $day): array => $day['events'])->pluck('description'))->toContain('Strategy Alpha created')
        ->and(collect($data['activityDays'])->flatMap(fn (array $day): array => $day['events'])->firstWhere('description', 'Strategy Alpha created')['actor'])->toBe($user->name)
        ->and(collect($data['activityDays'])->flatMap(fn (array $day): array => $day['events'])->firstWhere('description', 'Strategy Alpha created')['type'])->toBe('Strategy Created')
        ->and(collect($data['activityDays'])->flatMap(fn (array $day): array => $day['events'])->firstWhere('description', 'Activity from a non-member actor')['actor'])->toBe('System')
        ->and(collect($data['activityDays'])->flatMap(fn (array $day): array => $day['events'])->pluck('description'))
        ->not->toContain('Foreign strategy activity');
});

it('refreshes activity and current metrics on the Livewire polling action', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    Goal::factory()->create(['enterprise_id' => $enterprise->id, 'name' => 'Existing goal']);
    Event::query()->delete();

    $this->actingAs($user);
    $component = \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('aria-label="Goals: 1"', false)
        ->assertSee('No activity has been recorded for this enterprise in the last 14 days.');

    Goal::factory()->create(['enterprise_id' => $enterprise->id, 'name' => 'Newly created goal']);

    $component->call('setActivityRange', 'today')
        ->assertSee('Recorded activity · Today')
        ->call('refreshDashboard')
        ->assertSee('aria-label="Goals: 2"', false)
        ->assertSee('Newly created goal')
        ->assertDontSee('No activity has been recorded for this enterprise in the last 14 days.');
});

it('filters activity by today or the last fourteen days without inferring history', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));

    try {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
        $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
        Event::query()->delete();

        Event::factory()->create([
            'organization_id' => $organization->id,
            'enterprise_id' => $enterprise->id,
            'description' => 'Recent historical activity',
            'occurred_at' => now()->subDays(2),
        ]);
        Event::factory()->create([
            'organization_id' => $organization->id,
            'enterprise_id' => $enterprise->id,
            'description' => 'Activity today',
            'occurred_at' => now()->subHour(),
        ]);

        $this->actingAs($user);
        $service = app(\App\Services\Dashboard\OverviewDashboardData::class);
        $today = $service->forEnterprise($enterprise, 'today');
        $recent = $service->forEnterprise($enterprise, '14_days');

        expect($today['activityRangeLabel'])->toBe('Today')
            ->and(collect($today['activityDays'])->flatMap(fn (array $day): array => $day['events'])->pluck('description')->all())
            ->toBe(['Activity today'])
            ->and($recent['activityRangeLabel'])->toBe('Last 14 days')
            ->and(collect($recent['activityDays'])->flatMap(fn (array $day): array => $day['events'])->pluck('description')->all())
            ->toContain('Activity today', 'Recent historical activity');
    } finally {
        $this->travelBack();
    }
});

it('groups activity using the configured application timezone', function () {
    config(['app.timezone' => 'America/New_York']);
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-30 12:00:00', 'UTC'));

    try {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
        $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
        Event::query()->delete();
        Event::factory()->create([
            'organization_id' => $organization->id,
            'enterprise_id' => $enterprise->id,
            'description' => 'Late evening local activity',
            'occurred_at' => \Illuminate\Support\Carbon::parse('2026-10-30 02:00:00', 'UTC'),
        ]);

        $this->actingAs($user);
        $data = app(\App\Services\Dashboard\OverviewDashboardData::class)->forEnterprise($enterprise);

        expect($data['activityDays'][0]['date'])->toBe('Thursday, 29 October')
            ->and($data['activityDays'][0]['events'][0]['time'])->toBe('22:00');
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});

it('revalidates enterprise membership and switches to another authorized enterprise on refresh', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    $firstMembership = Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $firstOrganization]);
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $secondOrganization]);
    $firstEnterprise = Enterprise::factory()->create(['organization_id' => $firstOrganization, 'name' => 'A Private enterprise']);
    $secondEnterprise = Enterprise::factory()->create(['organization_id' => $secondOrganization, 'name' => 'B Accessible enterprise']);
    Event::query()->delete();
    Event::factory()->create([
        'organization_id' => $firstOrganization->id,
        'enterprise_id' => $firstEnterprise->id,
        'description' => 'Private enterprise activity',
    ]);
    Event::factory()->create([
        'organization_id' => $secondOrganization->id,
        'enterprise_id' => $secondEnterprise->id,
        'description' => 'Accessible enterprise activity',
    ]);

    $this->actingAs($user);
    $component = \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('Private enterprise activity');

    $firstMembership->delete();

    $component->call('refreshDashboard')
        ->assertSee('B Accessible enterprise')
        ->assertSee('Accessible enterprise activity')
        ->assertDontSee('Private enterprise activity');

    expect(WorkOverview::canAccess())->toBeTrue();
});

it('bounds the activity feed to the 100 most recent events', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    Event::query()->delete();
    Event::factory()->count(105)->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'occurred_at' => now(),
    ]);

    $this->actingAs($user);
    $data = app(\App\Services\Dashboard\OverviewDashboardData::class)->forEnterprise($enterprise);

    expect(collect($data['activityDays'])->sum(fn (array $day): int => count($day['events'])))->toBe(100);
});
