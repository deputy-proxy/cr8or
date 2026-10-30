<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Dashboard\LifecycleChartData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.timezone' => 'Europe/Bucharest']);
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'Europe/Bucharest'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function lifecycleChartUserAndEnterprise(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    return [$user, $enterprise, $organization];
}

it('calculates daily lifecycle metrics across all five entity types using local half-open days', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $yesterdayStart = Carbon::parse('2026-10-29 00:00:00', 'Europe/Bucharest');

    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('goals')->where('id', $goal->id)->update([
        'created_at' => $yesterdayStart->copy()->subDay()->utc(),
        'closed_at' => $yesterdayStart->copy()->setTime(12, 0)->utc(),
    ]);

    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('objectives')->where('id', $objective->id)->update([
        'created_at' => $yesterdayStart->copy()->setTime(9, 0)->utc(),
    ]);

    $task = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('tasks')->where('id', $task->id)->update([
        'created_at' => $yesterdayStart->copy()->subDay()->utc(),
        'closed_at' => $yesterdayStart->copy()->setTime(14, 0)->utc(),
        'status' => 'done',
    ]);

    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $milestone = Milestone::factory()->forProject($project)->create();
    DB::table('milestones')->where('id', $milestone->id)->update([
        'created_at' => $yesterdayStart->copy()->subDay()->utc(),
    ]);

    $issue = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'closed']);
    DB::table('issues')->where('id', $issue->id)->update([
        'github_created_at' => $yesterdayStart->copy()->subDay()->utc(),
        'github_closed_at' => $yesterdayStart->copy()->setTime(15, 0)->utc(),
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days');
    $yesterday = collect($data['days'])->firstWhere('date', '2026-10-29');

    expect($data['available'])->toBeTrue()
        ->and($data['entityOptions'])->toHaveKeys(['all', 'goals', 'objectives', 'tasks', 'milestones', 'issues'])
        ->and($yesterday['metrics'])->toBe([
            'open_start' => 4,
            'open_end' => 2,
            'created' => 1,
            'closed' => 3,
        ])
        ->and($data['days'][13]['metrics']['open_end'])->toBeNull()
        ->and($data['series'][0]['path'])->not->toBe('');
});

it('marks lifecycle metrics incomplete when legacy closed records have no close timestamp', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $legacyClosed = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'archived']);
    DB::table('goals')->where('id', $legacyClosed->id)->update([
        'created_at' => Carbon::parse('2026-10-20 09:00:00', 'Europe/Bucharest')->utc(),
        'closed_at' => null,
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days', 'goals');

    expect($data['days'][0]['metrics']['open_start'])->toBeNull()
        ->and($data['days'][0]['metrics']['open_end'])->toBeNull()
        ->and($data['days'][0]['metrics']['closed'])->toBeNull()
        ->and($data['days'][0]['metrics']['created'])->toBe(0)
        ->and($data['limitations'])->toContain('Goals: 1 currently closed record(s) have no reliable close timestamp; open/closed history is incomplete.');
});

it('marks open and close history incomplete when a tracked entity has been reopened', function () {
    [$user, $enterprise, $organization] = lifecycleChartUserAndEnterprise();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id]);
    Event::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'event_type' => 'open',
        'subject_type' => $goal->getMorphClass(),
        'subject_id' => (string) $goal->id,
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days', 'goals');

    expect($data['days'][0]['metrics']['open_start'])->toBeNull()
        ->and($data['days'][0]['metrics']['closed'])->toBeNull()
        ->and($data['days'][0]['metrics']['created'])->toBe(0)
        ->and($data['limitations'])->toContain('Goals: at least one record was reopened; the single close timestamp cannot reconstruct its earlier lifecycle intervals.');
});

it('does not include another enterprise records in lifecycle totals and revalidates the selected entity type', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    Goal::factory()->count(2)->create(['enterprise_id' => $enterprise->id]);
    Goal::factory()->count(5)->create(['enterprise_id' => $otherEnterprise->id]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, 'today', 'goals');

    expect($data['days'][0]['metrics']['created'])->toBe(2)
        ->and(fn () => app(WorkOverview::class)->setLifecycleEntityType('not-a-real-type'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('renders the lifecycle chart alongside the live activity timeline and refreshes its entity filter', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    Goal::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($user);
    \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('Lifecycle')
        ->assertSee('Open at start')
        ->assertSee('Open at end')
        ->assertSee('Created')
        ->assertSee('Closed')
        ->assertSee('Daily activity')
        ->call('setLifecycleEntityType', 'goals')
        ->assertSee('value="goals" selected', false);
});

it('uses a twenty-three-hour local day across the daylight-saving transition', function () {
    config(['app.timezone' => 'America/New_York']);
    Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:00', 'America/New_York'));
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();

    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('goals')->where('id', $goal->id)->update([
        'created_at' => Carbon::parse('2026-03-08 00:30:00', 'America/New_York')->utc(),
        'closed_at' => Carbon::parse('2026-03-08 23:30:00', 'America/New_York')->utc(),
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days', 'goals');
    $transitionDay = collect($data['days'])->firstWhere('date', '2026-03-08');

    expect($transitionDay['metrics'])->toBe([
        'open_start' => 0,
        'open_end' => 0,
        'created' => 1,
        'closed' => 1,
    ]);
});

it('does not invent GitHub creation or closure dates when provider timestamps are missing', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $issue = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'closed']);
    DB::table('issues')->where('id', $issue->id)->update([
        'github_created_at' => null,
        'github_closed_at' => null,
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, 'today', 'issues');

    expect($data['days'][0]['metrics'])->toBe([
        'open_start' => null,
        'open_end' => null,
        'created' => null,
        'closed' => null,
    ])
        ->and($data['limitations'])->toContain('GitHub Issues: 1 currently closed record(s) have no reliable close timestamp; open/closed history is incomplete.')
        ->and($data['limitations'])->toContain('GitHub Issues: 1 record(s) have no provider creation timestamp; opening history is incomplete.');
});

it('does not count future-timestamped records in the partial current day', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $futureGoal = Goal::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('goals')->where('id', $futureGoal->id)->update([
        'created_at' => Carbon::parse('2026-10-30 13:00:00', 'Europe/Bucharest')->utc(),
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, 'today', 'goals');

    expect($data['days'][0]['metrics']['created'])->toBe(0)
        ->and($data['days'][0]['metrics']['open_end'])->toBeNull();
});

it('refreshes lifecycle chart values with the existing dashboard polling action', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    Goal::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($user);
    $component = \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('Created: 1 on 2026-10-30');

    Goal::factory()->create(['enterprise_id' => $enterprise->id]);

    $component->call('refreshDashboard')
        ->assertSee('Created: 2 on 2026-10-30');
});
