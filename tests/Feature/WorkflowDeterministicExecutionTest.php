<?php

use App\Experts\OperationsExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\UpdateWorkItemTool;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use App\Models\WorkItem;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;
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

function publishedWorkflow(Workflow $workflow, User $actor): WorkflowVersion
{
    return app(WorkflowVersionService::class)->publish($workflow, $actor, 'publish:'.$workflow->getKey().':'.uniqid());
}

it('uses the same Capability boundary and Operation for Workflow and direct MCP business execution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);

    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'operations',
    ], [
        'runtime_class' => OperationsExpert::class,
        'enabled' => true,
    ]);

    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $workflowItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Workflow before',
    ]);
    $directItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Direct before',
    ]);

    WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => 'update-work',
        'name' => 'Update work',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['operations'],
        'capability_slugs' => ['work.item.update'],
        'input_contract' => [
            'required' => ['work_item_id', 'name'],
        ],
        'output_contract' => [],
    ]);

    $version = publishedWorkflow($workflow, $actor);

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        [
            'work_item_id' => $workflowItem->getKey(),
            'name' => 'Workflow after',
        ],
        'workflow-equivalent-'.$workflowItem->getKey(),
        'workflow-equivalent-correlation',
    );

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateWorkItemTool::class, [
            'work_item_id' => $directItem->getKey(),
            'name' => 'Direct after',
        ])
        ->assertOk();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($workflowItem->refresh()->name)->toBe('Workflow after')
        ->and($directItem->refresh()->name)->toBe('Direct after')
        ->and($execution->outputs['update-work'])->toBeArray();
});

it('executes a multi-stage published workflow without creating an AgentExecution or requiring a ModelProvider', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Deterministic business analysis']);

    governedStage($workflow, 'research', 1);
    governedStage($workflow, 'strategy', 2, ['research'], ['stages']);
    $version = publishedWorkflow($workflow, $actor);

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['request' => 'Analyze the enterprise.'],
        'workflow-test-1',
        'workflow-correlation-1',
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->workflow_version_id)->toBe($version->id)
        ->and($execution->workflow_version)->toBe($version->version)
        ->and($execution->correlation_id)->toBe('workflow-correlation-1')
        ->and($execution->outputs)->toHaveKeys(['research', 'strategy'])
        ->and(AgentExecution::query()->count())->toBe(0);
});

it('persists a failed execution when a started workflow stage fails', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1, [], ['required_request']);
    $version = publishedWorkflow($workflow, $actor);

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        [],
        'durable-failure',
        'durable-failure-correlation',
    ))->toThrow(AuthorizationException::class, 'missing required input [required_request]');

    $execution = WorkflowExecution::query()
        ->where('workflow_version_id', $version->id)
        ->where('idempotency_key', 'durable-failure')
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe(WorkflowExecution::STATUS_FAILED)
        ->and($execution->failure_reason)->toContain('missing required input [required_request]')
        ->and($execution->correlation_id)->toBe('durable-failure-correlation');
});

it('refuses to execute draft workflow definitions', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);

    $draft = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'stage_definitions' => [['key' => 'research', 'sequence' => 1]],
    ]);

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $actor,
        $draft,
        [],
        'draft-execution',
    ))->toThrow(AuthorizationException::class, 'requires a published WorkflowVersion');
});

it('refuses to start a retired WorkflowVersion', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);
    $version = publishedWorkflow($workflow, $actor);
    $version->retire()->save();

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $actor,
        $version->refresh(),
        ['request' => 'retired'],
        'retired-execution',
    ))->toThrow(AuthorizationException::class, 'requires a published WorkflowVersion');

    expect(WorkflowExecution::query()->count())->toBe(0);
});

it('enforces stage dependencies and input contracts before capability execution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1, [], ['required_request']);
    governedStage($workflow, 'strategy', 2, ['research']);
    $version = publishedWorkflow($workflow, $actor);

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        [],
        'workflow-missing-input',
    ))->toThrow(AuthorizationException::class, 'missing required input [required_request]');

    expect(AgentExecution::query()->count())->toBe(0);
});

it('is idempotent per published WorkflowVersion', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);
    $version = publishedWorkflow($workflow, $actor);

    $service = app(WorkflowExecutionService::class);
    $first = $service->start($actor, $version, ['request' => 'same'], 'same-key', 'same-correlation');
    $second = $service->start($actor, $version, ['request' => 'different'], 'same-key', 'different-correlation');

    expect($second->is($first))->toBeTrue()
        ->and(WorkflowExecution::query()->where('workflow_version_id', $version->id)->count())->toBe(1)
        ->and($second->input)->toBe(['request' => 'same'])
        ->and($second->correlation_id)->toBe('same-correlation');
});

it('supports pausing and resuming a workflow execution without an AgentExecution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);
    $version = publishedWorkflow($workflow, $actor);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
        'status' => WorkflowExecution::STATUS_PENDING,
    ]);

    $execution->start()->save();
    $execution->pause('Human requested a pause.')->save();
    expect($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_PAUSED);

    $completed = app(WorkflowExecutionService::class)->continue($actor, $execution);
    expect($completed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and(AgentExecution::query()->count())->toBe(0);
});

it('keeps published versions immutable and historical executions bound to their version', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $stage = governedStage($workflow, 'research', 1);
    $versionOne = publishedWorkflow($workflow, $actor);

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $versionOne,
        ['request' => 'historical'],
        'historical-key',
    );

    expect(fn () => $versionOne->update(['name' => 'mutated']))->toThrow(LogicException::class, 'immutable');

    $stage->update(['name' => 'New design']);
    $versionTwo = publishedWorkflow($workflow, $actor);

    expect($versionTwo->version)->toBe($versionOne->version + 1)
        ->and($versionOne->refresh()->stage_definitions[0]['name'])->toBe('Research')
        ->and($versionTwo->stage_definitions[0]['name'])->toBe('New design')
        ->and($execution->refresh()->workflow_version_id)->toBe($versionOne->id)
        ->and($execution->workflowVersion->version)->toBe($versionOne->version);
});

it('supports draft revision, idempotent publishing and retirement', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);
    $published = publishedWorkflow($workflow, $actor);

    $draft = app(WorkflowVersionService::class)->createDraft($published, $actor, 'revision-1');
    $draft->setAttribute('stage_definitions', [[
        'key' => 'research-v2',
        'name' => 'Research v2',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'input_contract' => ['required' => ['request']],
        'output_contract' => ['required' => ['analysis']],
        'repeatable' => false,
        'completion_criteria' => [],
    ]])->save();

    $publishedTwo = app(WorkflowVersionService::class)->publishVersion($draft, $actor, 'publish-revision-1');

    expect($publishedTwo->version)->toBe($published->version + 1)
        ->and($draft->refresh()->status)->toBe(WorkflowVersion::STATUS_PUBLISHED)
        ->and($workflow->refresh()->published_version_id)->toBe($publishedTwo->id)
        ->and($published->refresh()->status)->toBe(WorkflowVersion::STATUS_RETIRED);

    $same = app(WorkflowVersionService::class)->publishVersion($publishedTwo, $actor, 'publish-revision-1');
    expect($same->is($publishedTwo))->toBeTrue();
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
    publishedWorkflow($workflow, workflowActor($enterprise));

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $foreignActor,
        $workflow,
        [],
        'foreign-key',
    ))->toThrow(AuthorizationException::class);

    expect(WorkflowExecution::query()->count())->toBe(0)
        ->and(Gate::forUser($foreignActor)->allows('view', $workflow))->toBeFalse();
});

it('rejects invalid workflow execution lifecycle transitions', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = workflowActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    governedStage($workflow, 'research', 1);
    $version = publishedWorkflow($workflow, $actor);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
    ]);

    $execution->start()->complete()->save();

    expect(fn () => $execution->pause('too late'))->toThrow(LogicException::class, 'cannot transition from [completed] to [paused]');
});