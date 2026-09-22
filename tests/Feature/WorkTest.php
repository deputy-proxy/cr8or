<?php

use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Gate;

it('persists projects and work hierarchy with planning relationships', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $objective = \App\Models\Objective::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);
    $plan = Plan::factory()->create(['strategy_id' => $strategy]);
    $initiative = Initiative::factory()->create(['plan_id' => $plan]);

    $project = Project::factory()->create([
        'enterprise_id' => $enterprise,
        'strategy_id' => $strategy,
        'plan_id' => $plan,
        'initiative_id' => $initiative,
    ]);
    $task = Task::factory()->forProject($project)->create();
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'project_id' => $project]);

    expect($project->enterprise->is($enterprise))->toBeTrue()
        ->and($project->strategy->is($strategy))->toBeTrue()
        ->and($project->plan->is($plan))->toBeTrue()
        ->and($project->initiative->is($initiative))->toBeTrue()
        ->and($project->tasks->contains($task))->toBeTrue()
        ->and($project->workItems->contains($workItem))->toBeTrue()
        ->and($task->project->is($project))->toBeTrue();
});

it('denies cross organization work access through policies', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise]);

    expect(Gate::forUser($user)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', $task))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $task))->toBeFalse();
});

it('preserves project ownership when unrelated fields change', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Original']);
    $project->update(['name' => 'Renamed', 'description' => 'Updated']);

    expect($project->fresh()->enterprise_id)->toBe($enterprise->id)
        ->and($project->fresh()->name)->toBe('Renamed');
});

it('does not grant authority when a user is assigned to work', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise]);

    $assignment = \App\Models\Assignment::factory()->create([
        'enterprise_id' => $enterprise,
        'assignable_type' => Task::class,
        'assignable_id' => $task->id,
        'user_id' => $member->id,
    ]);

    expect($assignment->assignable->is($task))->toBeTrue()
        ->and($assignment->user->is($member))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $task))->toBeFalse();
});
