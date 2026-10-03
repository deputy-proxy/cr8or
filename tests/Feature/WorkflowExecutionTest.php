<?php

use App\Filament\Resources\WorkflowExecutions\WorkflowExecutionResource;
use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;

function workflowExecutionFixture(): array
{
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $enterprise->organization_id,
    ]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
    ]);
    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
    ]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise,
        'actor_id' => $actor,
    ]);

    return compact('enterprise', 'actor', 'workflow', 'version', 'execution');
}

it('removes the legacy generic workflow job and execution schema', function () {
    expect(Schema::hasTable('workflow_jobs'))->toBeFalse()
        ->and(Schema::hasTable('executions'))->toBeFalse()
        ->and(Schema::hasColumn('generation_jobs', 'workflow_job_id'))->toBeFalse()
        ->and(Schema::hasColumn('generation_jobs', 'execution_id'))->toBeFalse()
        ->and(Schema::hasColumn('render_jobs', 'workflow_job_id'))->toBeFalse()
        ->and(Schema::hasColumn('render_jobs', 'execution_id'))->toBeFalse();
});

it('uses WorkflowExecution as the canonical persisted workflow runtime record', function () {
    ['workflow' => $workflow, 'version' => $version, 'execution' => $execution] = workflowExecutionFixture();

    expect($execution->workflow->is($workflow))->toBeTrue()
        ->and($execution->workflowVersion->is($version))->toBeTrue()
        ->and($workflow->executions()->whereKey($execution)->exists())->toBeTrue()
        ->and(method_exists($workflow, 'jobs'))->toBeFalse();
});

it('enforces the WorkflowExecution lifecycle', function () {
    ['execution' => $execution] = workflowExecutionFixture();

    $execution->start()->save();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_RUNNING)
        ->and($execution->started_at)->not->toBeNull();

    $execution->fail('Provider unavailable')->save();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_FAILED)
        ->and($execution->failure_reason)->toBe('Provider unavailable')
        ->and($execution->completed_at)->not->toBeNull();

    $execution->retry()->save();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_RUNNING)
        ->and($execution->failure_reason)->toBeNull();

    $execution->complete()->save();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->completed_at)->not->toBeNull();

    expect(fn () => $execution->fail('late failure')->save())
        ->toThrow(LogicException::class, 'cannot transition from [completed] to [failed]');
});

it('rejects workflow executions that reference a non-published version', function () {
    $enterprise = Enterprise::factory()->create();
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => WorkflowVersion::STATUS_DRAFT,
    ]);

    expect(fn () => WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise,
    ]))->toThrow(LogicException::class, 'published WorkflowVersion');
});

it('keeps WorkflowExecution authorization scoped to its enterprise', function () {
    ['enterprise' => $enterprise, 'actor' => $actor, 'execution' => $execution] = workflowExecutionFixture();

    $foreignOrganization = Organization::factory()->create();
    $foreignUser = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $foreignUser,
        'organization_id' => $foreignOrganization,
    ]);

    expect(Gate::forUser($actor)->allows('view', $execution))->toBeTrue()
        ->and(Gate::forUser($foreignUser)->allows('view', $execution))->toBeFalse()
        ->and($enterprise->organization_id)->not->toBe($foreignOrganization->id);
});

it('keeps workflow and workflow execution Filament resources aligned with the canonical models', function () {
    expect(WorkflowResource::getModel())->toBe(Workflow::class)
        ->and(WorkflowExecutionResource::getModel())->toBe(WorkflowExecution::class)
        ->and(WorkflowExecutionResource::getPages())->toHaveKey('index');
});