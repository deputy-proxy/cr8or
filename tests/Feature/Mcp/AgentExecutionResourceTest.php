<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CancelAgentExecutionTool;
use App\Mcp\Tools\ContinueAgentExecutionTool;
use App\Mcp\Tools\CreateAgentExecutionTool;
use App\Mcp\Tools\ExecuteAgentTool;
use App\Mcp\Tools\GetExecutionTool;
use App\Mcp\Tools\ListExecutionTool;
use App\Mcp\Tools\ResumeAgentExecutionTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function executionMcpActor(): array
{
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::query()->firstOrCreate(['runtime_class' => App\Agents\CeoAgent::class], ['slug' => 'execution-test-agent', 'enabled' => true]);
    $assignment = AgentAssignment::query()->create(['agent_descriptor_id' => $agent->getKey(), 'organization_id' => $enterprise->organization_id, 'enterprise_id' => $enterprise->getKey(), 'enabled' => true, 'status' => AgentAssignment::STATUS_READY]);

    return [$user, $enterprise, $assignment];
}

it('registers the Agent Execution resource and governed runtime tools', function (): void {
    [$user] = executionMcpActor();
    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([CreateAgentExecutionTool::class, ExecuteAgentTool::class, GetExecutionTool::class, ListExecutionTool::class, ResumeAgentExecutionTool::class, CancelAgentExecutionTool::class]);
});

it('creates a durable execution idempotently and exposes structured state', function (): void {
    Queue::fake();
    [$user,$enterprise,$assignment] = executionMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');
    $input = ['enterprise_id' => $enterprise->getKey(), 'agent_assignment_id' => $assignment->getKey(), 'prompt' => 'Perform the requested enterprise task.', 'mode' => 'autonomous', 'idempotency_key' => 'execution-contract-1'];
    $server->tool(CreateAgentExecutionTool::class, $input)->assertOk()->assertSee('execution-contract-1');
    $server->tool(CreateAgentExecutionTool::class, $input)->assertOk();
    expect(\App\Models\AgentExecution::query()->count())->toBe(1);
    $execution = \App\Models\AgentExecution::query()->firstOrFail();
    $server->tool(GetExecutionTool::class, ['enterprise_id' => $enterprise->getKey(), 'agent_execution_id' => $execution->getKey()])->assertOk()->assertSee('Perform the requested enterprise task.');
    $server->tool(ListExecutionTool::class, ['enterprise_id' => $enterprise->getKey(), 'status' => 'requested'])->assertOk()->assertSee((string) $execution->getKey());
});

it('rejects execution from a draft assignment and cross-enterprise inspection', function (): void {
    [$user,$enterprise,$assignment] = executionMcpActor();
    $assignment->update(['status' => AgentAssignment::STATUS_DRAFT]);
    Cr8orServer::actingAs($user, 'api')->tool(CreateAgentExecutionTool::class, ['enterprise_id' => $enterprise->getKey(), 'agent_assignment_id' => $assignment->getKey(), 'prompt' => 'blocked', 'mode' => 'autonomous'])->assertHasErrors();
    $foreign = Enterprise::factory()->create();
    $foreignAssignment = AgentAssignment::query()->create(['agent_descriptor_id' => $assignment->agent_descriptor_id, 'organization_id' => $foreign->organization_id, 'enterprise_id' => $foreign->getKey(), 'enabled' => true, 'status' => AgentAssignment::STATUS_READY]);
    Cr8orServer::actingAs($user, 'api')->tool(GetExecutionTool::class, ['enterprise_id' => $foreign->getKey(), 'agent_execution_id' => 999999])->assertHasErrors();
});

it('starts through the governed agent.execute capability and cancels durably', function (): void {
    Queue::fake();
    [$user, $enterprise, $assignment] = executionMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');
    $response = $server->tool(ExecuteAgentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'prompt' => 'Execute through the governed capability.',
        'mode' => 'autonomous',
        'idempotency_key' => 'governed-execution-1',
    ]);
    $response->assertOk()->assertSee('governed-execution-1');
    $execution = App\Models\AgentExecution::query()->firstOrFail();
    $server->tool(CancelAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'reason' => 'Cancelled by test.',
    ])->assertOk()->assertSee('cancelled');
    expect($execution->refresh()->status)->toBe(App\Models\AgentExecution::STATUS_CANCELLED);
});
it('exposes a durable interactive continuation without invoking a model provider', function (): void {
    [$user, $enterprise, $assignment] = executionMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $response = $server->tool(CreateAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'prompt' => 'Continue this governed interactive task.',
        'mode' => 'interactive',
        'idempotency_key' => 'interactive-continuation-1',
    ]);

    $response->assertOk()->assertSee('continue-agent-execution')->assertSee('waiting_for_input');
    $execution = App\Models\AgentExecution::query()->firstOrFail();
    expect($execution->mode->value)->toBe('interactive')
        ->and($execution->status)->toBe(App\Models\AgentExecution::STATUS_WAITING_FOR_INPUT)
        ->and($execution->current_step)->toBe(0);
});

it('accepts exactly one structured continuation and rejects stale steps', function (): void {
    [$user, $enterprise, $assignment] = executionMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(CreateAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'prompt' => 'Run one interactive reasoning turn.',
        'mode' => 'interactive',
        'idempotency_key' => 'interactive-continuation-2',
    ])->assertOk();

    $execution = App\Models\AgentExecution::query()->firstOrFail();
    $input = [
        'enterprise_id' => $enterprise->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'expected_step' => 1,
        'idempotency_key' => 'reasoning-turn-1',
        'reasoning' => 'The requested work can proceed through the governed capability boundary.',
        'capability_requests' => [],
        'delegation_requests' => [],
        'termination' => 'continue',
        'termination_reason' => 'Continue to the next reasoning turn.',
    ];

    $server->tool(ContinueAgentExecutionTool::class, $input)->assertOk()->assertSee('reasoning');
    expect($execution->refresh()->status)->toBe(App\Models\AgentExecution::STATUS_REASONING)
        ->and($execution->current_step)->toBe(1)
        ->and($execution->steps()->count())->toBe(1);

    $server->tool(ContinueAgentExecutionTool::class, $input)->assertOk()->assertSee('reasoning');

    $complete = [...$input, 'expected_step' => 2, 'idempotency_key' => 'reasoning-turn-2', 'termination' => 'completed', 'termination_reason' => 'Complete.'];
    $server->tool(ContinueAgentExecutionTool::class, $complete)->assertOk()->assertSee('completed');
    expect($execution->refresh()->status)->toBe(App\Models\AgentExecution::STATUS_COMPLETED);

    $stale = [...$input, 'expected_step' => 1, 'idempotency_key' => 'reasoning-turn-stale'];
    $server->tool(ContinueAgentExecutionTool::class, $stale)->assertHasErrors();
});