<?php

use App\Filament\Resources\Executions\ExecutionResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Enterprise;
use App\Models\Execution;
use App\Models\Job;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use LogicException;

it('creates a correlated workflow, job and execution for enterprise work', function () {
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
    $job = Job::factory()->create(['workflow_id' => $workflow]);
    $execution = Execution::factory()->create(['workflow_job_id' => $job]);

    expect($job->workflow->is($workflow))->toBeTrue()
        ->and($execution->job->is($job))->toBeTrue()
        ->and($execution->organization->is($enterprise->organization))->toBeTrue()
        ->and($execution->enterprise->is($enterprise))->toBeTrue()
        ->and($execution->project->is($project))->toBeTrue()
        ->and($execution->task->is($task))->toBeTrue()
        ->and($execution->workItem->is($workItem))->toBeTrue()
        ->and($execution->organization_name)->toBe($enterprise->organization->name)
        ->and($execution->enterprise_name)->toBe($enterprise->name)
        ->and($execution->project_name)->toBe($project->name)
        ->and($execution->task_name)->toBe($task->name)
        ->and($execution->work_item_name)->toBe($workItem->name);
});

it('enforces explicit pending running succeeded and failed lifecycle transitions', function () {
    $workflow = Workflow::factory()->create();
    $job = Job::factory()->create(['workflow_id' => $workflow]);
    $execution = Execution::factory()->create(['workflow_job_id' => $job]);

    $workflow->transitionTo(Workflow::STATUS_RUNNING)->save();
    $job->start()->save();
    $execution->start()->save();
    $execution->succeed()->save();
    $job->succeed()->save();
    $workflow->transitionTo(Workflow::STATUS_SUCCEEDED)->save();

    expect($workflow->status)->toBe(Workflow::STATUS_SUCCEEDED)
        ->and($job->status)->toBe(Job::STATUS_SUCCEEDED)
        ->and($execution->status)->toBe(Execution::STATUS_SUCCEEDED)
        ->and($job->attempts)->toBe(1)
        ->and($job->started_at)->not->toBeNull()
        ->and($execution->completed_at)->not->toBeNull();

    expect(fn () => $execution->fail('late failure')->save())
        ->toThrow(LogicException::class, 'cannot transition from [succeeded] to [failed]');
});

it('preserves the originating execution context after source records change', function () {
    $organization = Organization::factory()->create(['name' => 'Original Organization']);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'Original Enterprise']);
    $project = Project::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Original Project']);
    $task = Task::factory()->forProject($project)->create(['name' => 'Original Task']);
    $workItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'name' => 'Original Work Item',
    ]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'task_id' => $task,
        'work_item_id' => $workItem,
    ]);
    $execution = Execution::factory()->create(['workflow_job_id' => Job::factory()->create(['workflow_id' => $workflow])]);

    $enterprise->update(['name' => 'Changed Enterprise']);
    $project->update(['name' => 'Changed Project']);
    $task->update(['name' => 'Changed Task']);
    $workItem->update(['name' => 'Changed Work Item']);

    $execution->enterprise_name = 'Tampered Enterprise';
    $execution->project_name = 'Tampered Project';
    $execution->save();
    $execution->refresh();

    expect($execution->organization_name)->toBe('Original Organization')
        ->and($execution->enterprise_name)->toBe('Original Enterprise')
        ->and($execution->project_name)->toBe('Original Project')
        ->and($execution->task_name)->toBe('Original Task')
        ->and($execution->work_item_name)->toBe('Original Work Item');
});

it('prevents failed execution from being represented as succeeded', function () {
    $execution = Execution::factory()->create();

    $execution->fail('Provider unavailable')->save();

    expect(fn () => $execution->succeed()->save())
        ->toThrow(LogicException::class, 'cannot transition from [failed] to [succeeded]');

    $execution->refresh();

    expect($execution->status)->toBe(Execution::STATUS_FAILED)
        ->and($execution->failure_reason)->toBe('Provider unavailable')
        ->and($execution->enterprise_id)->toBe($execution->job->workflow->enterprise_id);
});

it('retries the same logical job without creating a duplicate idempotency key', function () {
    $job = Job::factory()->create();
    $job->start()->save();
    $job->fail('Temporary failure')->save();

    $key = $job->idempotency_key;
    $job->retry()->save();
    $job->refresh();

    expect($job->status)->toBe(Job::STATUS_PENDING)
        ->and($job->idempotency_key)->toBe($key)
        ->and($job->attempts)->toBe(1)
        ->and($job->started_at)->toBeNull()
        ->and(Job::query()->where('idempotency_key', $key)->count())->toBe(1);

    expect(fn () => Job::factory()->create([
        'workflow_id' => $job->workflow_id,
        'idempotency_key' => $key,
    ]))->toThrow(QueryException::class);
});

it('prevents workflow work context from crossing enterprise boundaries', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $foreignEnterprise]);

    expect(fn () => Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
    ]))->toThrow(LogicException::class, 'project_id must belong to its enterprise');
});

it('enforces organization isolation for workflow, job and execution records', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $foreignMember = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    Membership::factory()->create([
        'user_id' => $foreignMember,
        'organization_id' => $otherOrganization,
    ]);

    $workflow = Workflow::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $organization])]);
    $foreignWorkflow = Workflow::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization])]);
    $job = Job::factory()->create(['workflow_id' => $workflow]);
    $foreignJob = Job::factory()->create(['workflow_id' => $foreignWorkflow]);
    $execution = Execution::factory()->create(['workflow_job_id' => $job]);
    $foreignExecution = Execution::factory()->create(['workflow_job_id' => $foreignJob]);

    expect(Gate::forUser($owner)->allows('view', $workflow))->toBeTrue()
        ->and(Gate::forUser($foreignMember)->allows('view', $workflow))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $job))->toBeTrue()
        ->and(Gate::forUser($foreignMember)->allows('view', $job))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $execution))->toBeTrue()
        ->and(Gate::forUser($foreignMember)->allows('view', $execution))->toBeFalse()
        ->and($foreignExecution->job->is($foreignJob))->toBeTrue();
});

it('scopes operational Filament resources to the authenticated organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);

    $workflow = Workflow::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $organization])]);
    $foreignWorkflow = Workflow::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization])]);
    $job = Job::factory()->create(['workflow_id' => $workflow]);
    $foreignJob = Job::factory()->create(['workflow_id' => $foreignWorkflow]);
    $execution = Execution::factory()->create(['workflow_job_id' => $job]);
    $foreignExecution = Execution::factory()->create(['workflow_job_id' => $foreignJob]);

    $this->actingAs($owner);

    expect(WorkflowResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($workflow->id)->not->toContain($foreignWorkflow->id)
        ->and(JobResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($job->id)->not->toContain($foreignJob->id)
        ->and(ExecutionResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($execution->id)->not->toContain($foreignExecution->id);
});
