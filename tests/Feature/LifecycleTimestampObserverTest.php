<?php

use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds nullable close timestamps to every CR8OR-owned lifecycle entity', function () {
    $this->travelTo(Carbon::parse('2026-10-30 09:00:00', config('app.timezone')));
    foreach (['goals', 'objectives', 'tasks', 'milestones'] as $table) {
        expect(Schema::hasColumn($table, 'closed_at'))->toBeTrue();
    }

    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $records = [
        Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'archived']),
        Objective::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'completed']),
        Task::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'done']),
        Milestone::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'completed']),
    ];

    foreach ($records as $record) {
        expect($record->fresh()->closed_at?->toDateTimeString())->toBe('2026-10-30 09:00:00');
    }
});

it('sets, preserves, clears, and resets close timestamps across lifecycle transitions', function () {
    $this->travelTo(Carbon::parse('2026-10-30 10:00:00', config('app.timezone')));

    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $records = [
        [Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']), 'archived', 'active'],
        [Objective::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']), 'completed', 'active'],
        [Task::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'todo']), 'done', 'todo'],
        [Milestone::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'planned']), 'completed', 'active'],
    ];

    foreach ($records as [$record, $closedStatus, $openStatus]) {
        $record->update(['status' => $closedStatus]);
        expect($record->fresh()->closed_at?->toDateTimeString())->toBe('2026-10-30 10:00:00');
    }

    $this->travelTo(Carbon::parse('2026-10-30 10:05:00', config('app.timezone')));
    foreach ($records as [$record]) {
        $record->update(['name' => $record->name.' updated']);
        expect($record->fresh()->closed_at?->toDateTimeString())->toBe('2026-10-30 10:00:00');
    }

    $this->travelTo(Carbon::parse('2026-10-30 10:10:00', config('app.timezone')));
    foreach ($records as [$record, $closedStatus, $openStatus]) {
        $record->update(['status' => $openStatus]);
        expect($record->fresh()->closed_at)->toBeNull();
    }

    $this->travelTo(Carbon::parse('2026-10-30 10:15:00', config('app.timezone')));
    foreach ($records as [$record, $closedStatus]) {
        $record->update(['status' => $closedStatus]);
        expect($record->fresh()->closed_at?->toDateTimeString())->toBe('2026-10-30 10:15:00');
    }
});

it('does not invent a close timestamp for legacy closed records on unrelated updates', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);

    DB::table('goals')->where('id', $goal->id)->update(['status' => 'archived']);
    $legacyClosedGoal = $goal->fresh();

    $legacyClosedGoal->update(['name' => 'Updated legacy record']);

    expect($legacyClosedGoal->fresh()->closed_at)->toBeNull();
});

it('records closure instants using the configured application timezone near a day boundary', function () {
    config(['app.timezone' => 'Europe/Bucharest']);
    $this->travelTo(Carbon::parse('2026-10-30 23:59:59', 'Europe/Bucharest'));

    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id]);
    $goal->update(['status' => 'archived']);

    expect($goal->fresh()->closed_at?->timezone('Europe/Bucharest')->toDateTimeString())
        ->toBe('2026-10-30 23:59:59');
});

it('maintains the close timestamp through the canonical Task service', function () {
    $enterprise = Enterprise::factory()->create();
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'todo']);

    app(\App\Services\TaskService::class)->update(
        \App\Models\User::factory()->create(),
        $task,
        ['status' => 'done'],
    );

    expect($task->fresh()->closed_at)->not->toBeNull();
});

it('can roll back and reapply the lifecycle close timestamp migration', function () {
    $migration = require database_path('migrations/2026_10_30_000001_add_closed_at_to_lifecycle_entities.php');

    $migration->down();
    foreach (['goals', 'objectives', 'tasks', 'milestones'] as $table) {
        expect(Schema::hasColumn($table, 'closed_at'))->toBeFalse();
    }

    $migration->up();
    foreach (['goals', 'objectives', 'tasks', 'milestones'] as $table) {
        expect(Schema::hasColumn($table, 'closed_at'))->toBeTrue();
    }
});
