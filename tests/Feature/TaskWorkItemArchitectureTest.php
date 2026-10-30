<?php

use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workflow;
use Illuminate\Support\Facades\Schema;

it('uses Task as the only canonical actionable work model', function () {
    expect(class_exists(\App\Models\WorkItem::class, false))->toBeFalse()
        ->and(Schema::hasTable('tasks'))->toBeTrue()
        ->and(Schema::hasTable('work_items'))->toBeFalse()
        ->and(Schema::getColumnListing('tasks'))->toContain('parent_task_id', 'priority', 'due_at', 'closed_at')
        ->and(Schema::hasColumn('workflows', 'task_id'))->toBeTrue()
        ->and(Schema::hasColumn('workflows', 'work_item_id'))->toBeFalse()
        ->and(Schema::hasColumn('decisions', 'task_id'))->toBeTrue()
        ->and(Schema::hasColumn('decisions', 'work_item_id'))->toBeFalse();
});

it('exposes canonical Task relationships for workflows, decisions, assignments, and dependencies', function () {
    $task = new Task;

    expect(method_exists($task, 'workflows'))->toBeTrue()
        ->and(method_exists($task, 'decisions'))->toBeTrue()
        ->and(method_exists($task, 'assignments'))->toBeTrue()
        ->and(method_exists($task, 'predecessorDependencies'))->toBeTrue()
        ->and(method_exists($task, 'successorDependencies'))->toBeTrue()
        ->and(method_exists($task, 'workItem'))->toBeFalse();
});

it('keeps Task hierarchy inside the same Enterprise and Project and rejects cycles', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $parent = Task::factory()->forProject($project)->create();
    $child = Task::factory()->forProject($project)->create(['parent_task_id' => $parent->id]);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children->contains($child))->toBeTrue();

    $otherEnterprise = Enterprise::factory()->create();
    $otherProject = Project::factory()->create(['enterprise_id' => $otherEnterprise->id]);

    expect(fn () => Task::factory()->forProject($otherProject)->create(['parent_task_id' => $parent->id]))
        ->toThrow(LogicException::class);
    expect(fn () => $parent->update(['parent_task_id' => $child->id]))
        ->toThrow(LogicException::class);
});

it('allows a Task to have workflow and decision context without merging their lifecycles', function () {
    $enterprise = Enterprise::factory()->create();
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->id,
        'task_id' => $task->id,
    ]);
    $decision = Decision::factory()->create([
        'enterprise_id' => $enterprise->id,
        'task_id' => $task->id,
    ]);

    expect($task->workflows->contains($workflow))->toBeTrue()
        ->and($task->decisions->contains($decision))->toBeTrue()
        ->and($workflow->status)->not->toBe($task->status);
});

it('prevents changing a parent Task scope while children would become inconsistent', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $otherProject = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $parent = Task::factory()->forProject($project)->create();
    Task::factory()->forProject($project)->create(['parent_task_id' => $parent->id]);

    expect(fn () => $parent->update(['project_id' => $otherProject->id]))
        ->toThrow(LogicException::class, 'while its children remain in another scope');
});
