<?php

use App\Models\Assignment;
use App\Models\Decision;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Initiative;
use App\Models\Issue;
use App\Models\Kpi;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;
use App\Operations\RecordKpiMeasurement;
use Illuminate\Support\Facades\DB;

it('records semantic open and closed transitions without noisy no-op transitions', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);

    $goal->update(['name' => 'Renamed without lifecycle change']);
    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $goal->getMorphClass())->whereIn('event_type', ['open', 'closed'])->count())->toBe(0);

    $goal->update(['status' => 'archived']);
    $goal->update(['status' => 'active']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $goal->getMorphClass())->whereIn('event_type', ['open', 'closed'])->orderBy('id')->pluck('event_type')->all())
        ->toBe(['closed', 'open']);
});

it('does not invent lifecycle transitions at creation and records only observed GitHub transitions', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::query()->create(['enterprise_id' => $enterprise->id, 'name' => 'Default status goal']);
    $objective = Objective::query()->create(['enterprise_id' => $enterprise->id, 'name' => 'Default status objective']);
    $issue = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'state' => 'open']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $goal->getMorphClass())->whereIn('event_type', ['open', 'closed'])->exists())->toBeFalse()
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $objective->getMorphClass())->whereIn('event_type', ['open', 'closed'])->exists())->toBeFalse()
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $issue->getMorphClass())->whereIn('event_type', ['open', 'closed'])->exists())->toBeFalse();

    $issue->update(['state' => 'closed', 'github_closed_at' => now(), 'github_updated_at' => now()]);
    $issue->update(['state' => 'closed', 'title' => 'An ordinary sync update']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $issue->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['closed'])
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $issue->getMorphClass())->where('event_type', 'issue.updated')->exists())->toBeTrue();
});

it('records Task and Milestone lifecycle transitions using their persisted status fields', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'todo']);
    $milestone = Milestone::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'planned']);

    $task->update(['status' => 'in_progress']);
    $task->update(['status' => 'done']);
    $milestone->update(['status' => 'completed']);

    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $task->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['closed'])
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $task->getMorphClass())->where('event_type', 'task.status_changed')->exists())->toBeTrue()
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->where('subject_type', $milestone->getMorphClass())->whereIn('event_type', ['open', 'closed'])->pluck('event_type')->all())
        ->toBe(['closed']);
});

it('records Strategy and Initiative activity through their Objective ownership chain', function () {
    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->id, 'name' => 'Strategy One']);
    $initiative = Initiative::factory()->create(['strategy_id' => $strategy->id, 'name' => 'Initiative One']);

    $strategy->update(['description' => 'Updated strategic direction']);
    $initiative->update(['description' => 'Updated initiative details']);

    $events = Event::query()->where('enterprise_id', $enterprise->id)->get();

    expect($events->where('subject_type', $strategy->getMorphClass())->pluck('event_type')->all())->toContain('strategy.created', 'strategy.updated')
        ->and($events->where('subject_type', $initiative->getMorphClass())->pluck('event_type')->all())->toContain('initiative.created', 'initiative.updated');
});

it('records KPI measurements, immutable decisions, enterprise decisions, dependencies, and assignments', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $enterprise->organization_id,
    ]);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise->id]);
    $recordMeasurement = app(RecordKpiMeasurement::class);
    $recordMeasurement->execute($user, ['kpi_id' => $kpi->getKey(), 'value' => 10, 'measured_at' => '2026-01-01']);
    $decision = Decision::factory()->create(['enterprise_id' => $enterprise->id]);
    $enterpriseDecision = EnterpriseDecision::factory()->create(['enterprise_id' => $enterprise->id]);
    $predecessor = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $successor = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $dependency = Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => $predecessor->getMorphClass(),
        'predecessor_id' => $predecessor->id,
        'successor_type' => $successor->getMorphClass(),
        'successor_id' => $successor->id,
    ]);
    $assignment = Assignment::factory()->create([
        'enterprise_id' => $enterprise->id,
        'assignable_type' => $predecessor->getMorphClass(),
        'assignable_id' => $predecessor->id,
        'user_id' => $user->id,
    ]);

    $recordMeasurement->execute($user, ['kpi_id' => $kpi->getKey(), 'value' => 14, 'measured_at' => '2026-02-01']);
    $recordMeasurement->execute($user, ['kpi_id' => $kpi->getKey(), 'value' => 13, 'measured_at' => '2026-03-01']);
    $recordMeasurement->execute($user, ['kpi_id' => $kpi->getKey(), 'value' => 14, 'measured_at' => '2026-04-01']);
    $enterpriseDecision->update(['summary' => 'Updated decision summary']);
    $assignment->update(['user_id' => $otherUser->id]);
    $dependency->delete();

    $events = Event::query()->where('enterprise_id', $enterprise->id)->get();

    expect($events->where('subject_type', $kpi->getMorphClass())->pluck('event_type')->all())->toContain('kpi.created', 'kpi.measurement_recorded')
        ->and($events->where('subject_type', $kpi->getMorphClass())->where('event_type', 'kpi.measurement_recorded')->count())->toBe(4)
        ->and($events->where('subject_type', $decision->getMorphClass())->pluck('event_type')->all())->toContain('decision.created')
        ->and($events->firstWhere('subject_type', $decision->getMorphClass())->actor_id)->toBe($decision->actor_id)
        ->and($events->where('subject_type', $enterpriseDecision->getMorphClass())->pluck('event_type')->all())->toContain('enterprise_decision.created', 'enterprise_decision.updated')
        ->and($events->where('subject_type', $dependency->getMorphClass())->pluck('event_type')->all())->toContain('dependency.created', 'dependency.deleted')
        ->and($events->where('subject_type', $assignment->getMorphClass())->pluck('event_type')->all())->toContain('assignment.created', 'assignment.updated');

    $count = $events->count();
    $kpi->current_value = 14;
    expect(fn () => $kpi->save())
        ->toThrow(LogicException::class, 'KPI current_value can only be changed by recording a measurement.');
    expect(Event::query()->where('enterprise_id', $enterprise->id)->count())->toBe($count);
});

it('rolls back activity events with the business transaction', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'status' => 'active']);
    $eventCount = Event::query()->where('enterprise_id', $enterprise->id)->count();

    expect(fn () => DB::transaction(function () use ($goal): void {
        $goal->update(['status' => 'archived']);
        throw new RuntimeException('force rollback');
    }))->toThrow(RuntimeException::class);

    expect($goal->fresh()->status)->toBe('active')
        ->and(Event::query()->where('enterprise_id', $enterprise->id)->count())->toBe($eventCount);
});
