<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ArchiveMemoryTool;
use App\Mcp\Tools\CreateMemoryTool;
use App\Mcp\Tools\GetMemoryTool;
use App\Mcp\Tools\ListMemoryTool;
use App\Mcp\Tools\RecordMemoryTool;
use App\Mcp\Tools\RetrieveMemoryTool;
use App\Mcp\Tools\UpdateMemoryTool;
use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;

function memoryMcpActor(): array
{
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);

    return [$user, $enterprise, $execution, AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id)];
}

it('registers resource and governed Memory MCP tools', function (): void {
    [$user] = memoryMcpActor();

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        CreateMemoryTool::class,
        GetMemoryTool::class,
        ListMemoryTool::class,
        UpdateMemoryTool::class,
        ArchiveMemoryTool::class,
        RetrieveMemoryTool::class,
        RecordMemoryTool::class,
    ]);
});

it('supports Memory resource CRUD and governed retrieval through MCP', function (): void {
    [$user, $enterprise, $execution, $agent] = memoryMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $created = $server->tool(CreateMemoryTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'source_execution_id' => $execution->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
        'statement' => 'The enterprise prefers concise campaign copy.',
        'confidence' => 0.9,
    ]);

    $created->assertOk()->assertSee('The enterprise prefers concise campaign copy.');
    $memory = AgentSemanticMemory::query()->firstOrFail();

    $server->tool(GetMemoryTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'memory_id' => $memory->getKey(),
    ])->assertOk()->assertSee('concise campaign copy');

    $server->tool(ListMemoryTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'search' => 'concise',
        'per_page' => 50,
    ])->assertOk()->assertSee('concise campaign copy');

    $server->tool(UpdateMemoryTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'memory_id' => $memory->getKey(),
        'source_execution_id' => $execution->getKey(),
        'statement' => 'The enterprise prefers concise copy with direct CTAs.',
        'confidence' => 0.95,
    ])->assertOk()->assertSee('direct CTAs');

    $server->tool(RetrieveMemoryTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
        'semantic_limit' => 10,
    ])->assertOk()->assertSee('direct CTAs');

    $server->tool(ArchiveMemoryTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'memory_id' => $memory->getKey(),
    ])->assertOk()->assertSee('archived');
});

it('records episodic Memory through the governed memory.record path', function (): void {
    [$user, $enterprise, $execution] = memoryMcpActor();

    Cr8orServer::actingAs($user, 'api')
        ->tool(RecordMemoryTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'persist' => true,
            'type' => 'episodic',
            'source_execution_id' => $execution->getKey(),
            'objective' => 'Complete the requested work.',
            'action' => 'Executed the governed workflow.',
            'result' => 'The workflow completed.',
            'outcome' => 'The workflow is available for reuse.',
        ])
        ->assertOk()
        ->assertSee('The workflow completed.');

    expect(AgentEpisodicMemory::query()->count())->toBe(1);
});

it('rejects cross-enterprise Memory through MCP', function (): void {
    [$user, $enterprise] = memoryMcpActor();
    $foreign = Enterprise::factory()->create();
    $foreignExecution = AgentExecution::factory()->forEnterprise($foreign)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateMemoryTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'type' => 'semantic',
            'source_execution_id' => $foreignExecution->getKey(),
            'agent_descriptor_id' => $foreignExecution->agent_descriptor_id,
            'statement' => 'foreign memory',
            'confidence' => 0.9,
        ])
        ->assertHasErrors();
});