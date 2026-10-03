<?php

use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

it('keeps Task as the hierarchical planning work concept', function () {
    expect(method_exists(Task::class, 'parent'))->toBeTrue()
        ->and(method_exists(Task::class, 'children'))->toBeTrue()
        ->and((new Task)->parent())->toBeInstanceOf(BelongsTo::class)
        ->and((new Task)->children())->toBeInstanceOf(HasMany::class)
        ->and((new Task)->getFillable())->toContain('priority', 'due_at', 'parent_task_id');
});

it('keeps WorkItem as the lightweight operational workflow subject', function () {
    expect(method_exists(WorkItem::class, 'workflow'))->toBeTrue()
        ->and((new WorkItem)->workflow())->toBeInstanceOf(HasOne::class)
        ->and(method_exists(WorkItem::class, 'parent'))->toBeFalse()
        ->and((new WorkItem)->getFillable())->not->toContain('priority', 'due_at', 'parent_task_id');
});

it('allows a workflow to carry complementary Task planning and WorkItem operational context', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $task = Task::factory()->forProject($project)->create();
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'project_id' => $project]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'task_id' => $task,
        'work_item_id' => $workItem,
    ]);

    expect($workflow->task->is($task))->toBeTrue()
        ->and($workflow->workItem->is($workItem))->toBeTrue()
        ->and($workflow->project->is($project))->toBeTrue();
});
