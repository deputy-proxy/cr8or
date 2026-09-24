<?php

use App\Filament\Resources\Decisions\DecisionResource;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('records a decision with explicit enterprise and strategic/work context', function () {
    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);
    $plan = Plan::factory()->create(['strategy_id' => $strategy]);
    $initiative = Initiative::factory()->create(['plan_id' => $plan]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise, 'project_id' => $project]);
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'project_id' => $project]);
    $actor = User::factory()->create(['name' => 'Decision Actor']);
    $decidedAt = Carbon::parse('2026-09-22 18:30:00');

    $decision = Decision::factory()->by($actor)->create([
        'enterprise_id' => $enterprise,
        'type' => 'strategic',
        'objective_id' => $objective,
        'strategy_id' => $strategy,
        'plan_id' => $plan,
        'initiative_id' => $initiative,
        'project_id' => $project,
        'task_id' => $task,
        'work_item_id' => $workItem,
        'decided_at' => $decidedAt,
    ]);

    $decision->refresh();

    expect($decision->enterprise->is($enterprise))->toBeTrue()
        ->and($decision->objective->is($objective))->toBeTrue()
        ->and($decision->strategy->is($strategy))->toBeTrue()
        ->and($decision->plan->is($plan))->toBeTrue()
        ->and($decision->initiative->is($initiative))->toBeTrue()
        ->and($decision->project->is($project))->toBeTrue()
        ->and($decision->task->is($task))->toBeTrue()
        ->and($decision->workItem->is($workItem))->toBeTrue()
        ->and($decision->actor->is($actor))->toBeTrue()
        ->and($decision->actor_name)->toBe('Decision Actor')
        ->and($decision->type)->toBe('strategic')
        ->and($decision->decided_at->equalTo($decidedAt))->toBeTrue();
});

it('preserves historical actor, timestamp and context when a decision is updated', function () {
    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);
    $actor = User::factory()->create(['name' => 'Historical Actor']);
    $decidedAt = Carbon::parse('2026-09-20 10:15:00');

    $decision = Decision::factory()->by($actor)->create([
        'enterprise_id' => $enterprise,
        'objective_id' => $objective,
        'strategy_id' => $strategy,
        'decided_at' => $decidedAt,
        'title' => 'Original decision',
        'summary' => 'Original summary',
        'rationale' => 'Original rationale',
    ]);

    $decision->update([
        'title' => 'Clarified decision',
        'summary' => 'Clarified summary',
        'type' => 'strategic',
        'actor_name' => 'Changed Actor',
        'objective_id' => null,
        'strategy_id' => null,
        'decided_at' => Carbon::parse('2026-09-23 13:00:00'),
    ]);
    $decision->refresh();

    expect($decision->actor_id)->toBe($actor->id)
        ->and($decision->actor_name)->toBe('Historical Actor')
        ->and($decision->type)->toBe('operational')
        ->and($decision->objective_id)->toBe($objective->id)
        ->and($decision->strategy_id)->toBe($strategy->id)
        ->and($decision->decided_at->equalTo($decidedAt))->toBeTrue()
        ->and($decision->title)->toBe('Clarified decision')
        ->and($decision->summary)->toBe('Clarified summary');
});

it('rejects context from another enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $foreignProject = Project::factory()->create(['enterprise_id' => $otherEnterprise]);

    expect(fn () => Decision::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $foreignProject,
    ]))->toThrow(LogicException::class, 'Decision context must belong to its enterprise.');
});

it('enforces decision authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $decision = Decision::factory()->create(['enterprise_id' => $enterprise]);
    $foreignDecision = Decision::factory()->create([
        'enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization]),
    ]);

    expect(Gate::forUser($owner)->allows('view', $decision))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $decision))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $decision))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $decision))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $decision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignDecision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Decision::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Decision::class, $enterprise]))->toBeFalse();
});

it('scopes decision administration to authorized organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);

    $decision = Decision::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $organization])]);
    $foreignDecision = Decision::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization])]);

    $this->actingAs($owner);

    expect(DecisionResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($decision->id)
        ->not->toContain($foreignDecision->id);
});

it('hides decision administration from users without organization membership', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(DecisionResource::canViewAny())->toBeFalse()
        ->and(DecisionResource::canCreate())->toBeFalse();
});

it('keeps decision schema distinct from existing decision records', function () {
    expect(Schema::getColumnListing('decisions'))->toBe([
        'id', 'enterprise_id', 'type', 'actor_id', 'actor_name',
        'objective_id', 'strategy_id', 'plan_id', 'initiative_id',
        'project_id', 'task_id', 'work_item_id',
        'title', 'summary', 'rationale', 'decided_at', 'created_at', 'updated_at',
    ]);
});
