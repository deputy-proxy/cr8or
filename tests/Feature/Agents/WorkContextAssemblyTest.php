<?php

use App\Models\Assignment;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Execution;
use App\Models\Job;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
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
    $job = Job::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'status' => Job::STATUS_RUNNING,
    ]);
    $execution = Execution::factory()->create([
        'workflow_job_id' => $job->getKey(),
        'status' => Execution::STATUS_FAILED,
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
            'job' => [
                'id' => $job->getKey(),
                'name' => $job->name,
                'status' => Job::STATUS_RUNNING,
                'attempts' => $job->attempts,
                'started_at' => null,
                'completed_at' => null,
            ],
            'execution' => [
                'id' => $execution->getKey(),
                'status' => Execution::STATUS_FAILED,
                'started_at' => null,
                'completed_at' => null,
                'failure_reason' => 'Provider unavailable',
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