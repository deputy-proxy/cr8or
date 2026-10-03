<?php

use App\Models\Enterprise;
use App\Models\Execution;
use App\Models\Job;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Schema;

it('keeps Task and WorkItem as distinct peer work concepts', function () {
    expect(method_exists(Task::class, 'workItem'))->toBeFalse()
        ->and(method_exists(WorkItem::class, 'task'))->toBeFalse();

    $taskColumns = Schema::getColumnListing((new Task)->getTable());
    $workItemColumns = Schema::getColumnListing((new WorkItem)->getTable());

    expect($taskColumns)
        ->toContain('parent_task_id', 'priority', 'due_at')
        ->and($workItemColumns)
        ->not->toContain('parent_task_id', 'priority', 'due_at');
});

it('allows Project to contain both Tasks and WorkItems independently', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $task = Task::factory()->forProject($project)->create();
    $workItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
    ]);

    expect($project->tasks->contains($task))->toBeTrue()
        ->and($project->workItems->contains($workItem))->toBeTrue()
        ->and($task->project->is($project))->toBeTrue()
        ->and($workItem->project->is($project))->toBeTrue();
});

it('allows a Workflow and Execution to preserve both Task and WorkItem context', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $task = Task::factory()->forProject($project)->create();
    $workItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
    ]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'task_id' => $task,
        'work_item_id' => $workItem,
    ]);
    $execution = Execution::factory()->create([
        'workflow_job_id' => Job::factory()->create(['workflow_id' => $workflow]),
    ]);

    expect($workflow->task->is($task))->toBeTrue()
        ->and($workflow->workItem->is($workItem))->toBeTrue()
        ->and($execution->task->is($task))->toBeTrue()
        ->and($execution->workItem->is($workItem))->toBeTrue();
});
