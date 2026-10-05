<?php

use App\Models\Assignment;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use App\Models\WorkItem;
use App\Services\McpContextAssembler;
use App\Services\WorkContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

it('assembles bounded authorized work context with coherent relationships and execution state', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $project = Project::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Launch project',
    ]);
    $parentTask = Task::factory()->forProject($project)->create([
        'name' => 'Parent task',
        'status' => 'in_progress',
    ]);
    $childTask = Task::factory()->forProject($project)->create([
        'parent_task_id' => $parentTask->getKey(),
        'name' => 'Child task',
        'due_at' => now()->addDay(),
    ]);
    $workItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Work item',
    ]);
    $milestone = Milestone::factory()->forProject($project)->create([
        'name' => 'Launch milestone',
    ]);

    $assignment = Assignment::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'assignable_type' => $childTask->getMorphClass(),
        'assignable_id' => $childTask->getKey(),
        'user_id' => $user->getKey(),
    ]);

    $dependency = Dependency::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'predecessor_type' => $parentTask->getMorphClass(),
        'predecessor_id' => $parentTask->getKey(),
        'successor_type' => $childTask->getMorphClass(),
        'successor_id' => $childTask->getKey(),
    ]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'task_id' => $childTask->getKey(),
        'work_item_id' => $workItem->getKey(),
        'status' => Workflow::STATUS_RUNNING,
    ]);
    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
    ]);
    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'workflow_version_id' => $version->getKey(),
        'workflow_version' => 1,
        'enterprise_id' => $enterprise->getKey(),
        'actor_id' => $user->getKey(),
        'status' => WorkflowExecution::STATUS_FAILED,
        'failure_reason' => 'Provider unavailable',
    ]);

    $data = app(WorkContextAssembler::class)->assemble($user, $enterprise);
    $agentContext = app(McpContextAssembler::class)->forAgent($user, $enterprise, ['work']);

    expect($agentContext->section('work')?->data)->toBe($data);

    $taskById = collect($data['tasks'])->keyBy('id');

    expect($data['enterprise']['id'])->toBe($enterprise->getKey())
        ->and($data['projects'])->toHaveCount(1)
        ->and($data['projects'][0]['id'])->toBe($project->getKey())
        ->and($data['tasks'])->toHaveCount(2)
        ->and($taskById[$childTask->getKey()])->toMatchArray([
            'id' => $childTask->getKey(),
            'project_id' => $project->getKey(),
            'parent_task_id' => $parentTask->getKey(),
            'name' => 'Child task',
        ])
        ->and($taskById[$childTask->getKey()]['parent'])->toMatchArray([
            'id' => $parentTask->getKey(),
            'name' => 'Parent task',
        ])
        ->and($taskById[$parentTask->getKey()]['children'])->toContain($childTask->getKey())
        ->and($data['work_items'][0]['id'])->toBe($workItem->getKey())
        ->and($data['milestones'][0]['id'])->toBe($milestone->getKey())
        ->and($data['assignments'][0])->toMatchArray([
            'id' => $assignment->getKey(),
            'assignable_type' => $childTask->getMorphClass(),
            'assignable_id' => $childTask->getKey(),
            'user_id' => $user->getKey(),
        ])
        ->and($data['dependencies'][0])->toMatchArray([
            'id' => $dependency->getKey(),
            'project_id' => $project->getKey(),
            'predecessor' => [
                'type' => $parentTask->getMorphClass(),
                'id' => $parentTask->getKey(),
            ],
            'successor' => [
                'type' => $childTask->getMorphClass(),
                'id' => $childTask->getKey(),
            ],
        ])
        ->and($data['execution_state'][0])->toMatchArray([
            'workflow' => [
                'id' => $workflow->getKey(),
                'name' => $workflow->name,
                'status' => Workflow::STATUS_RUNNING,
                'project_id' => $project->getKey(),
                'task_id' => $childTask->getKey(),
                'work_item_id' => $workItem->getKey(),
            ],
            'execution' => [
                'id' => $execution->getKey(),
                'workflow_version_id' => $version->getKey(),
                'workflow_version' => 1,
                'status' => WorkflowExecution::STATUS_FAILED,
                'current_stage_key' => null,
                'started_at' => null,
                'completed_at' => null,
                'failure_reason' => 'Provider unavailable',
                'correlation_id' => $execution->correlation_id,
                'idempotency_key' => $execution->idempotency_key,
            ],
        ]);
});

it('denies work context outside the users organization', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create([
        'organization_id' => $foreignOrganization->getKey(),
    ]);

    expect(fn () => app(WorkContextAssembler::class)->assemble($user, $foreignEnterprise))
        ->toThrow(AuthorizationException::class);
});

it('bounds each work context collection', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    Project::factory()->count(101)->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);
    Task::factory()->count(101)->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);
    WorkItem::factory()->count(101)->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);
    Milestone::factory()->count(101)->forProject(
        Project::factory()->create(['enterprise_id' => $enterprise->getKey()]),
    )->create();

    $data = app(WorkContextAssembler::class)->assemble($user, $enterprise);

    expect($data['projects'])->toHaveCount(100)
        ->and($data['tasks'])->toHaveCount(100)
        ->and($data['work_items'])->toHaveCount(100)
        ->and($data['milestones'])->toHaveCount(100);
});
it('does not broaden assignments when no work references are selected', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    Assignment::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'assignable_type' => Task::class,
        'assignable_id' => 999999,
        'user_id' => $user->getKey(),
    ]);

    $data = app(WorkContextAssembler::class)->assemble($user, $enterprise);

    expect($data['assignments'])->toBe([]);
});

it('loads task relationships and dependency endpoints with bounded query growth', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $project = Project::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);

    $parentTasks = Task::factory()->count(10)->forProject($project)->create();
    $childTasks = collect();

    foreach ($parentTasks as $parentTask) {
        $childTasks->push(Task::factory()->forProject($project)->create([
            'parent_task_id' => $parentTask->getKey(),
        ]));
    }

    foreach ($parentTasks->values() as $index => $parentTask) {
        Dependency::factory()->create([
            'enterprise_id' => $enterprise->getKey(),
            'project_id' => $project->getKey(),
            'predecessor_type' => $parentTask->getMorphClass(),
            'predecessor_id' => $parentTask->getKey(),
            'successor_type' => $parentTask->getMorphClass(),
            'successor_id' => $childTasks[$index]->getKey(),
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $data = app(WorkContextAssembler::class)->assemble($user, $enterprise);

    expect($queries)->toBeLessThan(20)
        ->and($data['tasks'])->toHaveCount(20)
        ->and($data['dependencies'])->toHaveCount(10);
});

it('returns only the latest workflow execution without hydrating execution payload columns', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);

    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
    ]);

    $first = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'workflow_version_id' => $version->getKey(),
        'workflow_version' => 1,
        'enterprise_id' => $enterprise->getKey(),
        'actor_id' => $user->getKey(),
        'status' => WorkflowExecution::STATUS_FAILED,
        'failure_reason' => 'first',
        'input' => ['large' => str_repeat('x', 5000)],
        'outputs' => ['stage' => ['value' => 'first']],
        'context' => ['large' => str_repeat('y', 5000)],
    ]);

    $latest = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'workflow_version_id' => $version->getKey(),
        'workflow_version' => 1,
        'enterprise_id' => $enterprise->getKey(),
        'actor_id' => $user->getKey(),
        'status' => WorkflowExecution::STATUS_FAILED,
        'failure_reason' => 'latest',
        'input' => ['large' => str_repeat('x', 5000)],
        'outputs' => ['stage' => ['value' => 'latest']],
        'context' => ['large' => str_repeat('y', 5000)],
    ]);

    $workflowExecutionQueries = [];
    DB::listen(function ($query) use (&$workflowExecutionQueries): void {
        if (str_contains(strtolower($query->sql), 'from `workflow_executions`')) {
            $workflowExecutionQueries[] = strtolower($query->sql);
        }
    });

    $data = app(WorkContextAssembler::class)->assemble($user, $enterprise);

    expect($data['execution_state'][0]['execution'])->toMatchArray([
        'id' => $latest->getKey(),
        'failure_reason' => 'latest',
    ])
        ->and($data['execution_state'][0]['execution']['id'])->not->toBe($first->getKey())
        ->and($data['execution_state'][0]['execution'])->not->toHaveKeys(['input', 'outputs', 'context']);
});