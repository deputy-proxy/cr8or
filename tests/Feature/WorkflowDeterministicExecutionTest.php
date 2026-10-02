<?php

use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Services\WorkflowExecutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use LogicException;

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'business-analysis',
    ], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

function workflowActor(Enterprise $enterprise): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    return $user;
}

function governedStage(Workflow $workflow, string $key, int $sequence, array $dependencies = [], array $inputContract = []): WorkflowStage
{
    return WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => $key,
        'name' => ucfirst($key),
        'sequence' => $sequence,
        'dependencies' => $dependencies,
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'input_contract' => ['required' => $inputContract],
        'output_contract' => ['required' => ['analysis']],
    ]);
}

it('executes a multi-stage workflow without creating an AgentExecution or requiring a ModelProvider', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Deterministic business analysis',
    ]);

    governedStage($workflow, 'research', 1);
    governedStage($workflow, 'strategy', 2, ['research'], ['stages']);

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $workflow,
        ['request' => 'Analyze the enterprise.'],
        'workflow-test-1',
        'workflow-correlation-1',
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->workflow_version)->toBe($workflow->version)
        ->and($execution->correlation_id)->toBe('workflow-correlation-1')
        ->and($execution->outputs)->toHaveKeys(['research', 'strategy'])
        ->and($execution->context['stages']['research'])->toHaveKey('analysis')
        ->and($execution->context['stages']['strategy'])->toHaveKey('analysis')
        ->and(AgentExecution::query()->count())->toBe(0)
        ->and($workflow->refresh()->status)->toBe(Workflow::STATUS_SUCCEEDED);
});

it('enforces stage dependencies and input contracts before capability execution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);

    $first = governedStage($workflow, 'research', 1, [], ['required_request']);
    $second = governedStage($workflow, 'strategy', 2, ['research']);

    expect($first->dependencies)->toBe([])
        ->and($second->dependencies)->toBe(['research']);

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $actor,
        $workflow,
        [],
        'workflow-missing-input',
    ))->toThrow(AuthorizationException::class, 'missing required input [required_request]');

    expect(AgentExecution::query()->count())->toBe(0);
});

it('is idempotent for repeated starts and preserves the first execution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);

    $service = app(WorkflowExecutionService::class);
    $first = $service->start($actor, $workflow, ['request' => 'same'], 'same-key', 'same-correlation');
    $second = $service->start($actor, $workflow, ['request' => 'different'], 'same-key', 'different-correlation');

    expect($second->is($first))->toBeTrue()
        ->and(WorkflowExecution::query()->where('workflow_id', $workflow->id)->count())->toBe(1)
        ->and($second->input)->toBe(['request' => 'same'])
        ->and($second->correlation_id)->toBe('same-correlation');
});

it('supports pausing and resuming a workflow execution without an AgentExecution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);

    $execution = WorkflowExecution::factory()->forEnterprise($enterprise)->create([
        'workflow_id' => $workflow,
        'workflow_version' => $workflow->version,
        'actor_id' => $actor,
        'status' => WorkflowExecution::STATUS_PENDING,
    ]);

    $execution->start()->save();
    $execution->pause('Human requested a pause.')->save();
    expect($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_PAUSED);

    $completed = app(WorkflowExecutionService::class)->continue($actor, $execution);
    expect($completed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and(AgentExecution::query()->count())->toBe(0);
});

it('fails closed across enterprise boundaries', function (): void {
    $enterprise = Enterprise::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $foreignActor = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $foreignActor,
        'organization_id' => $otherOrganization,
    ]);

    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $foreignActor,
        $workflow,
        [],
        'foreign-key',
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect(WorkflowExecution::query()->count())->toBe(0)
        ->and(Gate::forUser($foreignActor)->allows('view', $workflow))->toBeFalse();
});

it('rejects invalid workflow execution lifecycle transitions', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);

    $execution = WorkflowExecution::factory()->forEnterprise($enterprise)->create([
        'workflow_id' => $workflow,
        'workflow_version' => $workflow->version,
        'actor_id' => $actor,
    ]);

    $execution->start()->complete()->save();

    expect(fn () => $execution->pause('too late'))->toThrow(LogicException::class, 'cannot transition from [completed] to [paused]');
});