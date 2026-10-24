<?php

use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Issue;
use App\Models\LifecycleSnapshot;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Lifecycle\LifecycleHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

it('records only semantic open and closed transitions for lifecycle entities', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);

    $goal->update(['name' => 'Renamed without lifecycle change']);
    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $goal->getMorphClass())->whereIn('event_type', ['open', 'closed'])->count())->toBe(1);

    $goal->update(['status' => 'archived']);
    $goal->update(['status' => 'active']);

    $transitions = Event::query()->where('enterprise_id', $enterprise->id)
        ->where('subject_type', $goal->getMorphClass())->whereIn('event_type', ['open', 'closed'])
        ->orderBy('id')->pluck('event_type')->all();

    expect($transitions)->toBe(['open', 'closed', 'open']);
});

it('records initial open events when internal models use their model-level default statuses', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::query()->create(['enterprise_id' => $enterprise->id, 'name' => 'Default status goal']);
    $objective = Objective::query()->create(['enterprise_id' => $enterprise->id, 'name' => 'Default status objective']);

    expect($goal->status)->toBe('active')
        ->and($objective->status)->toBe('active')
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->whereIn('subject_type', [$goal->getMorphClass(), $objective->getMorphClass()])->where('event_type', 'open')->count())->toBe(2);
});

it('tracks Objective lifecycle using its explicit status field', function () {
    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);

    $objective->update(['description' => 'No transition']);
    $objective->update(['status' => 'completed']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $objective->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['open', 'closed']);
});

it('records GitHub Issue transitions only after an observed persisted state change', function () {
    $enterprise = Enterprise::factory()->create();
    $issue = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'open']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $issue->getMorphClass())->whereIn('event_type', ['open', 'closed'])->count())->toBe(0);

    $issue->update(['state' => 'closed', 'github_closed_at' => now(), 'github_updated_at' => now()]);
    $issue->update(['state' => 'closed', 'title' => 'An ordinary sync update']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $issue->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['closed']);
});

it('captures only start and end daily boundaries and marks late captures incomplete', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 23:59:00', config('app.timezone')));
    $enterprise = Enterprise::factory()->create();
    Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);
    $capturedAt = CarbonImmutable::parse('2026-10-24 00:00:30', config('app.timezone'));
    $this->travelTo($capturedAt);

    $processed = app(LifecycleHistoryService::class)->captureMidnight($capturedAt);

    expect($processed)->toBe(10)
        ->and(LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->count())->toBe(10)
        ->and(LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->whereIn('boundary', ['start', 'end'])->count())->toBe(10)
        ->and(LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->where('entity_type', 'goals')->where('boundary', 'start')->value('open_count'))->toBe(1)
        ->and(LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->where('entity_type', 'goals')->where('boundary', 'start')->value('is_complete'))->toBeTrue();

    app(LifecycleHistoryService::class)->captureMidnight(CarbonImmutable::parse('2026-10-24 00:10:00', config('app.timezone')));

    expect(LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->where('entity_type', 'goals')->where('boundary', 'start')->value('is_complete'))->toBeTrue();
});

it('does not expose daily history when boundary snapshots are missing or reconciliation fails', function () {
    $enterprise = Enterprise::factory()->create();
    $history = app(LifecycleHistoryService::class);
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $enterprise->organization_id]);
    $day = CarbonImmutable::parse('2026-10-22 00:00:00', config('app.timezone'));

    expect($history->dailySeries($user, $enterprise, 'goals', $day, $day))
        ->toMatchArray(['available' => false, 'reason' => 'missing_or_incomplete_boundary_snapshot', 'points' => []]);

    foreach ([LifecycleSnapshot::BOUNDARY_START, LifecycleSnapshot::BOUNDARY_END] as $boundary) {
        LifecycleSnapshot::query()->create([
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->id,
            'snapshot_date' => $day->toDateString(),
            'boundary' => $boundary,
            'entity_type' => 'goals',
            'open_count' => $boundary === LifecycleSnapshot::BOUNDARY_START ? 1 : 3,
            'is_complete' => true,
            'metadata' => [],
            'boundary_at' => $day,
            'captured_at' => $day,
        ]);
    }

    expect($history->dailySeries($user, $enterprise, 'goals', $day, $day))
        ->toMatchArray(['available' => false, 'reason' => 'daily_reconciliation_failed', 'points' => []]);
});

it('isolates snapshots by enterprise and organization', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $enterprise->organization_id]);
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $enterprise->organization_id]);
    $day = CarbonImmutable::parse('2026-10-22 00:00:00', config('app.timezone'));

    foreach ([LifecycleSnapshot::BOUNDARY_START, LifecycleSnapshot::BOUNDARY_END] as $boundary) {
        LifecycleSnapshot::query()->create([
            'organization_id' => $otherEnterprise->organization_id,
            'enterprise_id' => $otherEnterprise->id,
            'snapshot_date' => $day->toDateString(),
            'boundary' => $boundary,
            'entity_type' => 'goals',
            'open_count' => 0,
            'is_complete' => true,
            'metadata' => [],
            'boundary_at' => $day,
            'captured_at' => $day,
        ]);
    }

    expect(app(LifecycleHistoryService::class)->dailySeries($user, $enterprise, 'goals', $day, $day))
        ->toMatchArray(['available' => false, 'reason' => 'missing_or_incomplete_boundary_snapshot', 'points' => []]);
});

it('marks a first snapshot capture after the boundary grace window incomplete', function () {
    $capturedAt = CarbonImmutable::parse('2026-10-24 00:10:00', config('app.timezone'));
    $this->travelTo($capturedAt);
    $enterprise = Enterprise::factory()->create();

    app(LifecycleHistoryService::class)->captureMidnight($capturedAt);

    expect(LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->where('entity_type', 'goals')->where('boundary', 'start')->value('is_complete'))->toBeFalse();
});

it('records Task and Milestone lifecycle transitions using their status fields', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'todo']);
    $milestone = Milestone::factory()->forProject($project)->create(['status' => 'planned']);

    $task->update(['status' => 'done']);
    $milestone->update(['status' => 'completed']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $task->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['open', 'closed'])
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $milestone->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['open', 'closed']);
});

it('rolls back lifecycle events when the surrounding model transaction rolls back', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);

    expect(fn () => DB::transaction(function () use ($goal): void {
        $goal->update(['status' => 'archived']);
        throw new RuntimeException('force rollback');
    }))->toThrow(RuntimeException::class);

    expect($goal->fresh()->status)->toBe('active')
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $goal->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['open']);
});

it('returns a daily series only when boundary counts reconcile with transition occurrences', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $enterprise->organization_id]);
    $day = CarbonImmutable::parse('2026-10-22 00:00:00', config('app.timezone'));
    foreach ([LifecycleSnapshot::BOUNDARY_START, LifecycleSnapshot::BOUNDARY_END] as $boundary) {
        LifecycleSnapshot::query()->create([
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->id,
            'snapshot_date' => $day->toDateString(),
            'boundary' => $boundary,
            'entity_type' => 'goals',
            'open_count' => 2,
            'is_complete' => true,
            'metadata' => [],
            'boundary_at' => $boundary === LifecycleSnapshot::BOUNDARY_START ? $day : $day->addDay(),
            'captured_at' => $day,
        ]);
    }

    foreach ([['open', 2], ['closed', 1]] as [$eventType, $index]) {
        Event::factory()->create([
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->id,
            'event_type' => $eventType,
            'source_event_id' => 'series-test-'.$eventType,
            'subject_type' => Goal::class,
            'subject_id' => (string) $index,
            'occurred_at' => $day->addHours($index),
        ]);
    }

    expect(app(LifecycleHistoryService::class)->dailySeries($user, $enterprise, 'goals', $day, $day))
        ->toMatchArray([
            'available' => true,
            'reason' => null,
            'history_available_from' => '2026-10-22',
            'points' => [[
                'date' => '2026-10-22',
                'open_at_start' => 2,
                'open_at_end' => 2,
                'opened' => 1,
                'closed' => 1,
            ]],
        ]);

    $this->travelTo($day->addDays(2));
    expect(app(LifecycleHistoryService::class)->recentDailySeries($user, $enterprise, 'goals', 14))
        ->toMatchArray([
            'available' => true,
            'history_available_from' => '2026-10-22',
            'points' => [[
                'date' => '2026-10-22',
                'open_at_start' => 2,
                'open_at_end' => 2,
                'opened' => 1,
                'closed' => 1,
            ]],
        ]);
});

it('requires enterprise view authorization before returning lifecycle history', function () {
    $enterprise = Enterprise::factory()->create();
    $unrelatedUser = User::factory()->create();
    $day = CarbonImmutable::parse('2026-10-22 00:00:00', config('app.timezone'));

    expect(fn () => app(LifecycleHistoryService::class)->dailySeries($unrelatedUser, $enterprise, 'goals', $day, $day))
        ->toThrow(AuthorizationException::class);
});

it('keeps GitHub Issue snapshots incomplete while transition coverage is unverified', function () {
    $capturedAt = CarbonImmutable::parse('2026-10-24 00:00:30', config('app.timezone'));
    $this->travelTo(CarbonImmutable::parse('2026-10-23 23:59:00', config('app.timezone')));
    $enterprise = Enterprise::factory()->create(['github_issues_synced_at' => now()]);
    Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'open']);
    $this->travelTo($capturedAt);

    app(LifecycleHistoryService::class)->captureMidnight($capturedAt);

    $snapshot = LifecycleSnapshot::query()->where('enterprise_id', $enterprise->id)->where('entity_type', 'issues')->where('boundary', 'start')->firstOrFail();

    expect($snapshot->is_complete)->toBeFalse()
        ->and($snapshot->metadata['transition_coverage_verified'])->toBeFalse()
        ->and($snapshot->metadata['completeness_reason'])->toBe('github_transition_coverage_unverified');
});
