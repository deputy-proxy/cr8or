<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateWorkflowTool;
use App\Mcp\Tools\ListWorkflowsTool;
use App\Mcp\Tools\PublishWorkflowTool;
use App\Mcp\Tools\StartWorkflowTool;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'business-analysis',
    ], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

it('creates, publishes, discovers, and starts one generic Workflow for multiple enterprises through MCP', function (): void {
    $actor = User::factory()->create();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $organizationA,
    ]);
    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $organizationB,
    ]);

    $enterpriseA = Enterprise::factory()->create(['organization_id' => $organizationA]);
    $enterpriseB = Enterprise::factory()->create(['organization_id' => $organizationB]);

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(CreateWorkflowTool::class, [
        'enterprise_specific' => false,
        'name' => 'Generic Marketing Strategy',
        'canonical_key' => 'marketing.strategy.create.generic',
        'purpose' => 'Reusable workflow definition.',
        'stages' => [[
            'key' => 'research',
            'name' => 'Research',
            'sequence' => 1,
            'dependencies' => [],
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'input_contract' => ['required' => ['request']],
            'output_contract' => ['required' => ['analysis']],
        ]],
    ])->assertOk();

    $workflow = \App\Models\Workflow::query()
        ->where('canonical_key', 'marketing.strategy.create.generic')
        ->firstOrFail();

    expect($workflow->enterprise_specific)->toBeFalse()
        ->and($workflow->enterprise_id)->toBeNull();

    $server->tool(PublishWorkflowTool::class, [
        'enterprise_id' => $enterpriseA->getKey(),
        'workflow_id' => $workflow->getKey(),
        'idempotency_key' => 'publish-generic-workflow',
    ])->assertOk();

    $server->tool(ListWorkflowsTool::class, [
        'enterprise_id' => $enterpriseB->getKey(),
    ])->assertOk()->assertSee('Generic Marketing Strategy');

    $server->tool(StartWorkflowTool::class, [
        'enterprise_id' => $enterpriseB->getKey(),
        'workflow_id' => $workflow->getKey(),
        'input' => ['request' => 'Analyze Enterprise B.'],
        'idempotency_key' => 'generic-workflow-enterprise-b',
        'correlation_id' => 'generic-workflow-enterprise-b-correlation',
    ])->assertOk();

    $execution = \App\Models\WorkflowExecution::query()
        ->where('workflow_id', $workflow->getKey())
        ->firstOrFail();

    expect($execution->enterprise_id)->toBe($enterpriseB->getKey())
        ->and($execution->workflow_version_id)->toBe($workflow->refresh()->published_version_id);
});