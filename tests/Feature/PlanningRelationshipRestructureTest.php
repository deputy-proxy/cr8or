<?php

use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('makes Initiatives and Milestones directly Enterprise-owned', function () {
    $enterprise = Enterprise::factory()->create();
    $initiative = Initiative::factory()->create(['enterprise_id' => $enterprise->id]);
    $milestone = Milestone::factory()->create([
        'enterprise_id' => $enterprise->id,
        'target_date' => '2027-03-15',
    ]);

    expect($initiative->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->initiatives->contains($initiative))->toBeTrue()
        ->and($milestone->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->milestones->contains($milestone))->toBeTrue()
        ->and($milestone->fresh()->target_date->toDateString())->toBe('2027-03-15')
        ->and(method_exists($milestone, 'project'))->toBeFalse();
});

it('removes the Project to Initiative relationship while retaining Project Enterprise ownership', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);

    expect($project->enterprise->is($enterprise))->toBeTrue()
        ->and(method_exists($project, 'initiative'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'initiative_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'target_date'))->toBeTrue();
});

it('allows Tasks to belong to either a Project or an Initiative', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $initiative = Initiative::factory()->create(['enterprise_id' => $enterprise->id]);

    $projectTask = Task::factory()->forProject($project)->create();
    $initiativeTask = Task::factory()->forInitiative($initiative)->create();

    expect($projectTask->project->is($project))->toBeTrue()
        ->and($projectTask->initiative_id)->toBeNull()
        ->and($initiativeTask->initiative->is($initiative))->toBeTrue()
        ->and($initiativeTask->project_id)->toBeNull()
        ->and($project->tasks->contains($projectTask))->toBeTrue()
        ->and($initiative->tasks->contains($initiativeTask))->toBeTrue();
});

it('keeps Tasks as standalone Enterprise work when their Project or Initiative is deleted', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $initiative = Initiative::factory()->create(['enterprise_id' => $enterprise->id]);
    $projectTask = Task::factory()->forProject($project)->create();
    $initiativeTask = Task::factory()->forInitiative($initiative)->create();

    $project->delete();
    $initiative->delete();

    expect($projectTask->fresh()->project_id)->toBeNull()
        ->and($initiativeTask->fresh()->initiative_id)->toBeNull()
        ->and($projectTask->fresh()->enterprise_id)->toBe($enterprise->id)
        ->and($initiativeTask->fresh()->enterprise_id)->toBe($enterprise->id);
});

it('supports moving a Task between its Project and Initiative parent types', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $initiative = Initiative::factory()->create(['enterprise_id' => $enterprise->id]);
    $task = Task::factory()->forProject($project)->create();
    $actor = User::factory()->create();

    $movedToInitiative = app(TaskService::class)->update($actor, $task, [
        'project_id' => null,
        'initiative_id' => $initiative->id,
    ]);

    expect($movedToInitiative->project_id)->toBeNull()
        ->and($movedToInitiative->initiative_id)->toBe($initiative->id);

    $movedToProject = app(TaskService::class)->update($actor, $movedToInitiative, [
        'project_id' => $project->id,
        'initiative_id' => null,
    ]);

    expect($movedToProject->project_id)->toBe($project->id)
        ->and($movedToProject->initiative_id)->toBeNull();
});

it('rejects Tasks with both parent types and rejects cross-Enterprise parents', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $initiative = Initiative::factory()->create(['enterprise_id' => $enterprise->id]);
    $foreignInitiative = Initiative::factory()->create(['enterprise_id' => $otherEnterprise->id]);

    expect(fn () => Task::factory()->create([
        'enterprise_id' => $enterprise->id,
        'project_id' => $project->id,
        'initiative_id' => $initiative->id,
    ]))->toThrow(LogicException::class, 'Project or Initiative, not both');

    $standaloneTask = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    expect($standaloneTask->project_id)->toBeNull()
        ->and($standaloneTask->initiative_id)->toBeNull();

    expect(fn () => Task::factory()->forInitiative($foreignInitiative)->create(['enterprise_id' => $enterprise->id]))
        ->toThrow(LogicException::class, 'same Enterprise');
});

it('allows a standalone Task to remain Enterprise-owned', function () {
    $task = Task::factory()->create();

    expect($task->enterprise)->not->toBeNull()
        ->and($task->project_id)->toBeNull()
        ->and($task->initiative_id)->toBeNull();
});
