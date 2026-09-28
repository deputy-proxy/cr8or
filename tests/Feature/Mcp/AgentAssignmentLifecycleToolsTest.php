<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateAgentAssignmentTool;
use App\Mcp\Tools\GetAgentAssignmentTool;
use App\Mcp\Tools\ListAgentAssignmentsTool;
use App\Mcp\Tools\TransitionAgentAssignmentTool;
use App\Mcp\Tools\UpdateAgentAssignmentTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;

function assignmentMcpActor(): array
{
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
        'role' => App\Enums\MembershipRole::Admin,
    ]);
    $agent = AgentDescriptor::factory()->create(['enabled' => true]);

    return [$user, $enterprise, $agent];
}

it('registers the complete Agent Assignment lifecycle resource surface', function (): void {
    [$user] = assignmentMcpActor();

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        CreateAgentAssignmentTool::class,
        GetAgentAssignmentTool::class,
        ListAgentAssignmentsTool::class,
        UpdateAgentAssignmentTool::class,
        TransitionAgentAssignmentTool::class,
    ]);
});

it('supports create, list, update and explicit lifecycle transitions', function (): void {
    [$user, $enterprise, $agent] = assignmentMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $created = $server->tool(CreateAgentAssignmentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
        'objective' => 'Run the quarterly planning workflow.',
        'requirements' => ['source' => 'approved-data'],
        'context' => ['period' => 'Q4'],
        'idempotency_key' => 'q4-plan-1',
    ]);

    $created->assertOk()->assertSee('q4-plan-1');
    $assignment = AgentAssignment::query()->firstOrFail();

    $server->tool(GetAgentAssignmentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
    ])->assertOk()->assertSee('quarterly planning');

    $server->tool(UpdateAgentAssignmentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'objective' => 'Run the quarterly planning workflow with approved data.',
    ])->assertOk()->assertSee('approved data');

    $server->tool(TransitionAgentAssignmentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'status' => 'ready',
    ])->assertOk()->assertSee('ready');

    $server->tool(TransitionAgentAssignmentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'status' => 'running',
    ])->assertOk()->assertSee('running');

    $server->tool(ListAgentAssignmentsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'status' => 'running',
    ])->assertOk()->assertSee('quarterly planning');

    expect($assignment->refresh()->status)->toBe(AgentAssignment::STATUS_RUNNING)
        ->and($assignment->started_at)->not->toBeNull();
});

it('rejects an invalid lifecycle transition through MCP', function (): void {
    [$user, $enterprise, $agent] = assignmentMcpActor();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $agent->getKey(),
        'status' => AgentAssignment::STATUS_DRAFT,
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(TransitionAgentAssignmentTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'agent_assignment_id' => $assignment->getKey(),
            'status' => 'running',
        ])
        ->assertHasErrors();
});

it('rejects a cross-enterprise Agent Assignment resource access', function (): void {
    [$user, $enterprise, $agent] = assignmentMcpActor();
    $foreign = Enterprise::factory()->create();
    $assignment = AgentAssignment::factory()->forEnterprise($foreign)->create([
        'agent_descriptor_id' => $agent->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetAgentAssignmentTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'agent_assignment_id' => $assignment->getKey(),
        ])
        ->assertHasErrors();
});
