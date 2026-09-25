<?php

use App\Enums\MembershipRole;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\MarkContentPublicationReadyTool;
use App\Mcp\Tools\RequestApprovalTool;
use App\Mcp\Tools\SubmitContentForReviewTool;
use App\Mcp\Tools\UpdateContentItemTool;
use App\Mcp\Tools\UpdateStrategyTool;
use App\Mcp\Tools\UpdateWorkItemTool;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\ApprovalRequestService;

function mcpCapabilityOwner(User $user, Organization $organization): void
{
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
}

function mcpAgentContext(User $actor, Enterprise $enterprise): array
{
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()
        ->forAssignment($assignment)
        ->create([
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
        ]);

    return [$assignment, $execution];
}

it('registers the initial governed capability catalogue for an organization member', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpCapabilityOwner($user, $organization);

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        CreateContentItemTool::class,
        CreateStrategyTool::class,
        CreateWorkItemTool::class,
        MarkContentPublicationReadyTool::class,
        RequestApprovalTool::class,
        SubmitContentForReviewTool::class,
        UpdateContentItemTool::class,
        UpdateStrategyTool::class,
        UpdateWorkItemTool::class,
    ]);
});

it('allows an authorized human to create and update a work item', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpCapabilityOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);

    $server = Cr8orServer::actingAs($actor, 'api');

    $created = $server->tool(CreateWorkItemTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Initial work',
        'description' => 'Created through MCP.',
        'status' => 'planned',
    ]);

    $created->assertOk()->assertSee(['Initial work', 'Created through MCP.', 'planned']);

    $workItem = WorkItem::query()->where('name', 'Initial work')->firstOrFail();

    $server->tool(UpdateWorkItemTool::class, [
        'work_item_id' => $workItem->getKey(),
        'name' => 'Updated work',
        'status' => 'active',
    ])->assertOk();

    $server->tool(UpdateWorkItemTool::class, [
        'work_item_id' => $workItem->getKey(),
        'name' => 'Updated work',
        'status' => 'active',
    ])->assertOk();

    expect($workItem->refresh()->name)->toBe('Updated work')
        ->and($workItem->status)->toBe('active')
        ->and(WorkItem::query()->where('id', $workItem->getKey())->count())->toBe(1);
});

it('allows an authorized human to create and update a strategy', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpCapabilityOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise]);

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(CreateStrategyTool::class, [
        'objective_id' => $objective->getKey(),
        'name' => 'Initial strategy',
        'description' => 'Created through MCP.',
    ])->assertOk();

    $strategy = Strategy::query()->where('name', 'Initial strategy')->firstOrFail();

    $server->tool(UpdateStrategyTool::class, [
        'strategy_id' => $strategy->getKey(),
        'name' => 'Updated strategy',
    ])->assertOk();

    expect($strategy->refresh()->name)->toBe('Updated strategy')
        ->and($strategy->objective_id)->toBe($objective->getKey());
});

it('denies cross-organization human mutations', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    mcpCapabilityOwner($actor, $organization);

    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateWorkItemTool::class, [
            'enterprise_id' => $foreignEnterprise->getKey(),
            'name' => 'Should not exist',
        ])
        ->assertHasErrors();

    expect(WorkItem::query()->where('name', 'Should not exist')->exists())->toBeFalse();
});

it('rejects invalid input before mutating state', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpCapabilityOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateWorkItemTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => '',
        ])
        ->assertHasErrors();

    expect(WorkItem::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(0);
});

it('denies an Agent-backed mutation without the required capability', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    [$assignment, $execution] = mcpAgentContext($actor, $enterprise);
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateWorkItemTool::class, [
            'work_item_id' => $workItem->getKey(),
            'name' => 'Unauthorized',
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
        ])
        ->assertHasErrors();

    expect($workItem->refresh()->name)->not->toBe('Unauthorized');
});

it('denies a disabled Agent assignment even when the capability exists', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    [$assignment, $execution] = mcpAgentContext($actor, $enterprise);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.update',
    ]);
    $assignment->update(['enabled' => false]);
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateWorkItemTool::class, [
            'work_item_id' => $workItem->getKey(),
            'name' => 'Should remain unchanged',
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
        ])
        ->assertHasErrors();

    expect($workItem->refresh()->name)->not->toBe('Should remain unchanged');
});

it('requires and honors a matching approval for a sensitive Agent capability', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    [$assignment, $execution] = mcpAgentContext($actor, $enterprise);
    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.update',
    ]);
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise]);

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(UpdateWorkItemTool::class, [
        'work_item_id' => $workItem->getKey(),
        'name' => 'Blocked until approved',
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
    ])->assertHasErrors();

    $approval = app(ApprovalRequestService::class)->request(
        $actor,
        'work.update',
        $assignment,
        $execution,
        ['work_item_id' => $workItem->getKey()],
    );

    $approver = User::factory()->create();
    Membership::factory()->admin()->create([
        'user_id' => $approver->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    app(ApprovalRequestService::class)->approve($approval, $approver);

    $server->tool(UpdateWorkItemTool::class, [
        'work_item_id' => $workItem->getKey(),
        'name' => 'Approved update',
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'approval_request_id' => $approval->getKey(),
    ])->assertOk();

    expect($workItem->refresh()->name)->toBe('Approved update');
});

it('rejects a matching approval when its target context does not match the mutation', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    [$assignment, $execution] = mcpAgentContext($actor, $enterprise);
    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.update',
    ]);
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise]);

    $approval = app(ApprovalRequestService::class)->request(
        $actor,
        'work.update',
        $assignment,
        $execution,
        ['work_item_id' => $workItem->getKey() + 1],
    );
    $approver = User::factory()->create();
    Membership::factory()->admin()->create([
        'user_id' => $approver->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    app(ApprovalRequestService::class)->approve($approval, $approver);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateWorkItemTool::class, [
            'work_item_id' => $workItem->getKey(),
            'name' => 'Must remain unchanged',
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
            'approval_request_id' => $approval->getKey(),
        ])
        ->assertHasErrors();

    expect($workItem->refresh()->name)->not->toBe('Must remain unchanged');
});

it('rejects a cross-organization Agent execution target', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);
    [$assignment, $execution] = mcpAgentContext($actor, $enterprise);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.update',
    ]);
    $foreignWorkItem = WorkItem::factory()->create(['enterprise_id' => $foreignEnterprise]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateWorkItemTool::class, [
            'work_item_id' => $foreignWorkItem->getKey(),
            'name' => 'Foreign mutation',
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
        ])
        ->assertHasErrors();

    expect($foreignWorkItem->refresh()->name)->not->toBe('Foreign mutation');
});

it('creates approval requests only for enabled Agent assignments', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.update',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(RequestApprovalTool::class, [
            'agent_assignment_id' => $assignment->getKey(),
            'capability' => 'work.update',
            'target_context' => ['work_item_id' => 123],
        ])
        ->assertOk();

    expect(ApprovalRequest::query()
        ->where('agent_assignment_id', $assignment->getKey())
        ->where('capability', 'work.update')
        ->exists())->toBeTrue();

    $assignment->update(['enabled' => false]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(RequestApprovalTool::class, [
            'agent_assignment_id' => $assignment->getKey(),
            'capability' => 'work.update',
            'target_context' => ['work_item_id' => 456],
        ])
        ->assertHasErrors();
});

it('registers the foundational discovery tool surface', function () {
    $organization = \App\Models\Organization::factory()->create();
    \App\Models\Membership::factory()->create([
        'user_id' => \App\Models\User::query()->latest('id')->firstOrFail()->id,
        'organization_id' => $organization->id,
        'role' => \App\Enums\MembershipRole::Member,
    ]);

    $toolClasses = [
        \App\Mcp\Tools\ListEnterpriseTool::class, \App\Mcp\Tools\GetEnterpriseTool::class,
        \App\Mcp\Tools\ListObjectiveTool::class, \App\Mcp\Tools\GetObjectiveTool::class,
        \App\Mcp\Tools\ListStrategyTool::class, \App\Mcp\Tools\GetStrategyTool::class,
        \App\Mcp\Tools\ListWorkItemTool::class, \App\Mcp\Tools\GetWorkItemTool::class,
        \App\Mcp\Tools\ListAgentDescriptorTool::class, \App\Mcp\Tools\GetAgentDescriptorTool::class,
        \App\Mcp\Tools\ListExpertDescriptorTool::class, \App\Mcp\Tools\GetExpertDescriptorTool::class,
        \App\Mcp\Tools\ListCapabilitiesTool::class, \App\Mcp\Tools\GetCapabilityTool::class,
        \App\Mcp\Tools\ListCampaignTool::class, \App\Mcp\Tools\GetCampaignTool::class,
        \App\Mcp\Tools\ListContentSeriesTool::class, \App\Mcp\Tools\GetContentSeriesTool::class,
        \App\Mcp\Tools\ListContentItemTool::class, \App\Mcp\Tools\GetContentItemTool::class,
        \App\Mcp\Tools\ListAudienceTool::class, \App\Mcp\Tools\GetAudienceTool::class,
        \App\Mcp\Tools\ListChannelTool::class, \App\Mcp\Tools\GetChannelTool::class,
        \App\Mcp\Tools\ListExecutionTool::class, \App\Mcp\Tools\GetExecutionTool::class,
        \App\Mcp\Tools\ListApprovalRequestTool::class, \App\Mcp\Tools\GetApprovalRequestTool::class,
    ];

    \App\Mcp\Servers\Cr8orServer::actingAs(\App\Models\User::factory()->create(), 'api')
        ->tools()
        ->assertRegistered($toolClasses);
});

it('lists and gets strategy resources with filtering pagination and organization isolation', function () {
    $user = \App\Models\User::factory()->create();
    $organization = \App\Models\Organization::factory()->create();
    \App\Models\Membership::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'role' => \App\Enums\MembershipRole::Member,
    ]);
    $enterprise = \App\Models\Enterprise::factory()->create(['organization_id' => $organization->id]);
    $objective = \App\Models\Objective::factory()->create(['enterprise_id' => $enterprise->id]);
    \App\Models\Strategy::factory()->create(['objective_id' => $objective->id, 'name' => 'Discoverable strategy']);
    \App\Models\Strategy::factory()->create(['objective_id' => $objective->id, 'name' => 'Other strategy']);

    \App\Mcp\Servers\Cr8orServer::actingAs($user, 'api')
        ->tool(\App\Mcp\Tools\ListStrategyTool::class, [
            'search' => 'Discoverable',
            'objective_id' => $objective->id,
            'per_page' => 1,
            'page' => 1,
        ])
        ->assertOk()
        ->assertSee(['Discoverable strategy', '"total":1', '"last_page":1']);

    $strategy = \App\Models\Strategy::query()->where('name', 'Discoverable strategy')->firstOrFail();
    \App\Mcp\Servers\Cr8orServer::actingAs($user, 'api')
        ->tool(\App\Mcp\Tools\GetStrategyTool::class, ['id' => $strategy->id])
        ->assertOk()
        ->assertSee('Discoverable strategy');

    $foreignOrganization = \App\Models\Organization::factory()->create();
    $foreignEnterprise = \App\Models\Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $foreignObjective = \App\Models\Objective::factory()->create(['enterprise_id' => $foreignEnterprise->id]);
    $foreignStrategy = \App\Models\Strategy::factory()->create(['objective_id' => $foreignObjective->id]);

    \App\Mcp\Servers\Cr8orServer::actingAs($user, 'api')
        ->tool(\App\Mcp\Tools\GetStrategyTool::class, ['id' => $foreignStrategy->id])
        ->assertHasErrors();
});

it('discovers runtime capabilities through enabled descriptors', function () {
    $user = \App\Models\User::factory()->create();
    $organization = \App\Models\Organization::factory()->create();
    \App\Models\Membership::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'role' => \App\Enums\MembershipRole::Member,
    ]);
    \App\Models\AgentDescriptor::factory()->create([
        'slug' => 'operations',
        'runtime_class' => \App\Agents\OperationsAgent::class,
        'enabled' => true,
    ]);
    \App\Models\ExpertDescriptor::factory()->create([
        'slug' => 'operations',
        'runtime_class' => \App\Experts\OperationsExpert::class,
        'enabled' => true,
    ]);

    \App\Mcp\Servers\Cr8orServer::actingAs($user, 'api')
        ->tool(\App\Mcp\Tools\ListCapabilitiesTool::class, ['search' => 'work.'])
        ->assertOk()
        ->assertSee(['work.create', 'work.update']);

    \App\Mcp\Servers\Cr8orServer::actingAs($user, 'api')
        ->tool(\App\Mcp\Tools\GetCapabilityTool::class, ['id' => 'work.create'])
        ->assertOk()
        ->assertSee('work.create');
});