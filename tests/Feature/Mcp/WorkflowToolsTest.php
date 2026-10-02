<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateWorkflowTool;
use App\Mcp\Tools\GetWorkflowExecutionTool;
use App\Mcp\Tools\ListWorkflowsTool;
use App\Mcp\Tools\PublishWorkflowTool;
use App\Mcp\Tools\ResumeWorkflowExecutionTool;
use App\Mcp\Tools\StartWorkflowTool;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Services\CanonicalWorkflowProvisioner;
use App\Services\MarketingStrategyWorkflowDefinition;
use Illuminate\Support\Facades\Queue;

function workflowMcpOwner(User $user, Organization $organization): void
{
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
}

function workflowMcpInput(): array
{
    return [
        [
            'key' => 'research',
            'name' => 'Research',
            'sequence' => 1,
            'dependencies' => [],
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'input_contract' => ['required' => ['request']],
            'output_contract' => ['required' => ['analysis']],
        ],
    ];
}

beforeEach(function (): void {
    Queue::fake();

    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'business-analysis',
    ], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

it('registers the canonical Workflow MCP entry points', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowMcpOwner($user, $organization);

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        CreateWorkflowTool::class,
        PublishWorkflowTool::class,
        ListWorkflowsTool::class,
        StartWorkflowTool::class,
        GetWorkflowExecutionTool::class,
        ResumeWorkflowExecutionTool::class,
    ]);
});

it('creates publishes discovers starts inspects and resumes a Workflow without AgentExecution', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowMcpOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(CreateWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'name' => 'MCP deterministic workflow',
        'purpose' => 'Exercise the first-class workflow entry points.',
        'stages' => workflowMcpInput(),
    ])->assertOk();

    $workflow = \App\Models\Workflow::query()->where('enterprise_id', $enterprise->id)->where('name', 'MCP deterministic workflow')->firstOrFail();

    $server->tool(PublishWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'idempotency_key' => 'publish-mcp-workflow',
    ])->assertOk();

    expect($workflow->refresh()->published_version_id)->not->toBeNull();

    $server->tool(ListWorkflowsTool::class, [
        'enterprise_id' => $enterprise->id,
    ])->assertOk()->assertSee('MCP deterministic workflow');

    $server->tool(StartWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'input' => ['request' => 'Analyze this enterprise.'],
        'idempotency_key' => 'start-mcp-workflow',
    ])->assertOk();

    $execution = WorkflowExecution::query()->where('workflow_id', $workflow->id)->firstOrFail();
    $continuationToken = $execution->continuation_token;

    $server->tool(GetWorkflowExecutionTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_execution_id' => $execution->id,
        'continuation_token' => $continuationToken,
    ])->assertOk();

    $server->tool(ResumeWorkflowExecutionTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_execution_id' => $execution->id,
        'continuation_token' => $continuationToken,
    ])->assertOk();

    expect($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and(AgentExecution::query()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();
});

it('discovers a canonical Workflow by exact enterprise and canonical key', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowMcpOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $workflow = app(CanonicalWorkflowProvisioner::class)->provisionMarketingStrategy($enterprise, $actor);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListWorkflowsTool::class, [
            'enterprise_id' => $enterprise->id,
            'canonical_key' => MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE,
        ])
        ->assertOk()
        ->assertSee($workflow->name)
        ->assertSee(MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE)
        ->assertSee((string) $workflow->published_version_id);

    expect(AgentExecution::query()->count())->toBe(0);
});

it('returns no result for a canonical key belonging to another enterprise', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowMcpOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    app(CanonicalWorkflowProvisioner::class)->provisionMarketingStrategy($enterprise, $actor);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListWorkflowsTool::class, [
            'enterprise_id' => $otherEnterprise->id,
            'canonical_key' => MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE,
        ])
        ->assertOk()
        ->assertSee('"items":[]');

    expect(AgentExecution::query()->count())->toBe(0);
});

it('keeps Workflow start idempotent through the MCP entry point', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowMcpOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $server = Cr8orServer::actingAs($actor, 'api');
    $server->tool(CreateWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'name' => 'Idempotent workflow',
        'stages' => workflowMcpInput(),
    ])->assertOk();

    $workflow = \App\Models\Workflow::query()->where('name', 'Idempotent workflow')->firstOrFail();
    $server->tool(PublishWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'idempotency_key' => 'publish-idempotent',
    ])->assertOk();

    $server->tool(StartWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'input' => ['request' => 'same'],
        'idempotency_key' => 'same-start',
    ])->assertOk();

    $server->tool(StartWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'input' => ['request' => 'different'],
        'idempotency_key' => 'same-start',
    ])->assertOk();

    expect(WorkflowExecution::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('fails closed across enterprises for Workflow MCP entry points', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    workflowMcpOwner($actor, $organization);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListWorkflowsTool::class, [
            'enterprise_id' => $foreignEnterprise->id,
        ])
        ->assertHasErrors();

    expect(AgentExecution::query()->count())->toBe(0);
});