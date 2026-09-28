<?php

use App\Capabilities\CapabilityRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\RecordMemoryTool;
use App\Mcp\Tools\RetrieveMemoryTool;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;

it('registers memory.retrieve and memory.record as separate governed capabilities', function (): void {
    $registry = app(CapabilityRegistry::class);

    expect($registry->resolve('memory.retrieve')->operation)->toBe(App\Operations\RetrieveMemory::class)
        ->and($registry->resolve('memory.retrieve')->toolClass)->toBe(RetrieveMemoryTool::class)
        ->and($registry->resolve('memory.record')->operation)->toBe(App\Operations\RecordMemory::class)
        ->and($registry->resolve('memory.record')->toolClass)->toBe(RecordMemoryTool::class);
});

it('retrieves Memory through the governed capability boundary', function (): void {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);
    $agent = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

    \App\Models\AgentSemanticMemory::factory()->forAgent($agent, $enterprise)->create([
        'statement' => 'Memory is scoped to the Enterprise.',
        'confidence' => 0.9,
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(RetrieveMemoryTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'agent_descriptor_id' => $agent->getKey(),
            'semantic_limit' => 10,
        ])
        ->assertOk()
        ->assertSee('Memory is scoped to the Enterprise.');
});