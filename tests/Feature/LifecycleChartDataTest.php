<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
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

it('calculates independent daily opening and closing balances for objectives tasks and issues', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $yesterdayStart = Carbon::parse('2026-10-29 00:00:00', 'Europe/Bucharest');

    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('objectives')->where('id', $objective->id)->update([
        'created_at' => $yesterdayStart->copy()->setTime(9, 0)->utc(),
    ]);

    $task = Task::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'done']);
    DB::table('tasks')->where('id', $task->id)->update([
        'created_at' => $yesterdayStart->copy()->subDay()->utc(),
        'closed_at' => $yesterdayStart->copy()->setTime(14, 0)->utc(),
    ]);

    $issue = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'closed']);
    DB::table('issues')->where('id', $issue->id)->update([
        'github_created_at' => $yesterdayStart->copy()->subDay()->utc(),
        'github_closed_at' => $yesterdayStart->copy()->setTime(15, 0)->utc(),
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days');
    $entities = collect($data['entities'])->keyBy('key');

    expect($data['available'])->toBeTrue()
        ->and($entities->keys()->all())->toBe(['objectives', 'tasks', 'issues'])
        ->and(collect($entities['objectives']['days'])->firstWhere('date', '2026-10-29')['metrics'])->toBe([
            'open_start' => 0,
            'open_end' => 1,
            'created' => 1,
            'closed' => 0,
        ])
        ->and(collect($entities['tasks']['days'])->firstWhere('date', '2026-10-29')['metrics'])->toBe([
            'open_start' => 1,
            'open_end' => 0,
            'created' => 0,
            'closed' => 1,
        ])
        ->and(collect($entities['issues']['days'])->firstWhere('date', '2026-10-29')['metrics'])->toBe([
            'open_start' => 1,
            'open_end' => 0,
            'created' => 0,
            'closed' => 1,
        ])
        ->and(collect($entities['objectives']['days'])->firstWhere('date', '2026-10-29')['reconciles'])->toBeTrue()
        ->and($entities['objectives']['summary'])->toBe([
            'open_start' => 0,
            'open_end' => 1,
            'created' => 1,
            'closed' => 0,
        ])
        ->and(collect($entities['objectives']['chart']['days'][12]['bars'])->contains(
            fn (array $bar): bool => $bar['key'] === 'created' && $bar['value'] === 1,
        ))->toBeTrue()
        ->and($entities['objectives']['chart']['days'][12]['connectors'])->toHaveCount(3);
});

it('keeps midnight closures in the opening balance so each daily waterfall reconciles', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'completed']);
    DB::table('objectives')->where('id', $objective->id)->update([
        'created_at' => Carbon::parse('2026-10-28 09:00:00', 'Europe/Bucharest')->utc(),
        'closed_at' => Carbon::parse('2026-10-29 00:00:00', 'Europe/Bucharest')->utc(),
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days');
    $objectives = collect($data['entities'])->firstWhere('key', 'objectives');
    $dayBeforeClosure = collect($objectives['days'])->firstWhere('date', '2026-10-28');
    $closureDay = collect($objectives['days'])->firstWhere('date', '2026-10-29');

    expect($dayBeforeClosure['metrics'])->toBe([
        'open_start' => 0,
        'open_end' => 1,
        'created' => 1,
        'closed' => 0,
    ])
        ->and($closureDay['metrics'])->toBe([
            'open_start' => 1,
            'open_end' => 0,
            'created' => 0,
            'closed' => 1,
        ])
        ->and($dayBeforeClosure['reconciles'])->toBeTrue()
        ->and($closureDay['reconciles'])->toBeTrue();
});

it('marks lifecycle metrics incomplete when legacy closed records have no close timestamp', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $legacyClosed = Objective::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'archived']);
    DB::table('objectives')->where('id', $legacyClosed->id)->update([
        'created_at' => Carbon::parse('2026-10-20 09:00:00', 'Europe/Bucharest')->utc(),
        'closed_at' => null,
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days');
    $objectives = collect($data['entities'])->firstWhere('key', 'objectives');

    expect($objectives['days'][0]['metrics']['open_start'])->toBeNull()
        ->and($objectives['days'][0]['metrics']['open_end'])->toBeNull()
        ->and($objectives['days'][0]['metrics']['closed'])->toBeNull()
        ->and($objectives['days'][0]['metrics']['created'])->toBe(0)
        ->and($data['limitations'])->toContain('Objectives: 1 currently closed record(s) have no reliable close timestamp; open/closed history is incomplete.');
});

it('marks open and close history incomplete when a tracked entity has been reopened', function () {
    [$user, $enterprise, $organization] = lifecycleChartUserAndEnterprise();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    Event::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'event_type' => 'open',
        'subject_type' => $objective->getMorphClass(),
        'subject_id' => (string) $objective->id,
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days');
    $objectives = collect($data['entities'])->firstWhere('key', 'objectives');

    expect($objectives['days'][0]['metrics']['open_start'])->toBeNull()
        ->and($objectives['days'][0]['metrics']['closed'])->toBeNull()
        ->and($objectives['days'][0]['metrics']['created'])->toBe(0)
        ->and($data['limitations'])->toContain('Objectives: at least one record was reopened; the single close timestamp cannot reconstruct its earlier lifecycle intervals.');
});

it('does not include records from another enterprise in lifecycle totals', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    Objective::factory()->count(2)->create(['enterprise_id' => $enterprise->id]);
    Objective::factory()->count(5)->create(['enterprise_id' => $otherEnterprise->id]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, 'today');
    $objectives = collect($data['entities'])->firstWhere('key', 'objectives');

    expect($objectives['days'][0]['metrics']['created'])->toBe(2)
        ->and($data['entities'])->toHaveCount(3);
});

it('renders the daily waterfall charts and summary cards above them on the full content width', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    Task::factory()->create(['enterprise_id' => $enterprise->id]);
    Issue::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($user);
    \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('Daily lifecycle')
        ->assertSee('Objectives daily waterfall')
        ->assertSee('Tasks daily waterfall')
        ->assertSee('Issues daily waterfall')
        ->assertSee('Opening')
        ->assertSee('Opened')
        ->assertSee('Closed')
        ->assertSee('Closing')
        ->assertSee('Each day starts and ends independently')
        ->assertSee('aria-label="Lifecycle summary metrics"', false)
        ->assertSee('w-full min-w-0 space-y-4', false)
        ->assertSee('Daily activity')
        ->assertDontSee('Lifecycle entity type');
});

it('uses local calendar days across the daylight-saving transition', function () {
    config(['app.timezone' => 'America/New_York']);
    Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:00', 'America/New_York'));
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();

    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('objectives')->where('id', $objective->id)->update([
        'created_at' => Carbon::parse('2026-03-08 00:30:00', 'America/New_York')->utc(),
        'closed_at' => Carbon::parse('2026-03-08 23:30:00', 'America/New_York')->utc(),
        'status' => 'completed',
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, '14_days');
    $objectives = collect($data['entities'])->firstWhere('key', 'objectives');
    $transitionDay = collect($objectives['days'])->firstWhere('date', '2026-03-08');

    expect($transitionDay['metrics'])->toBe([
        'open_start' => 0,
        'open_end' => 0,
        'created' => 1,
        'closed' => 1,
    ])
        ->and($transitionDay['reconciles'])->toBeTrue();
});

it('does not invent GitHub creation or closure dates when provider timestamps are missing', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    $issue = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'closed']);
    DB::table('issues')->where('id', $issue->id)->update([
        'github_created_at' => null,
        'github_closed_at' => null,
    ]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, 'today');
    $issues = collect($data['entities'])->firstWhere('key', 'issues');

    expect($issues['days'][0]['metrics'])->toBe([
        'open_start' => null,
        'open_end' => null,
        'created' => null,
        'closed' => null,
    ])
        ->and($data['limitations'])->toContain('Issues: 1 currently closed record(s) have no reliable close timestamp; open/closed history is incomplete.')
        ->and($data['limitations'])->toContain('Issues: 1 record(s) have no provider creation timestamp; opening history is incomplete.');
});

it('does not show a closing balance for the current incomplete day', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    Objective::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($user);
    $data = app(LifecycleChartData::class)->forEnterprise($enterprise, 'today');
    $objectives = collect($data['entities'])->firstWhere('key', 'objectives');

    expect($objectives['days'][0]['metrics']['open_end'])->toBeNull()
        ->and($objectives['summary']['open_end'])->toBeNull()
        ->and(collect($objectives['chart']['days'][0]['bars'])->pluck('key'))->not->toContain('open_end');
});

it('refreshes lifecycle chart values with the existing dashboard polling action', function () {
    [$user, $enterprise] = lifecycleChartUserAndEnterprise();
    Objective::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($user);
    $component = \Livewire\Livewire::test(WorkOverview::class)
        ->assertSee('Objectives')
        ->assertSee('Opening');

    Objective::factory()->create(['enterprise_id' => $enterprise->id]);

    $component->call('refreshDashboard')
        ->assertSee('Objectives');
});
