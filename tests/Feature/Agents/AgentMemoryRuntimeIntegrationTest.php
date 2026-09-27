<?php

use App\Agents\Agent;
use App\Agents\AgentDefinition;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;

it('automatically injects governed memory into Agent and Expert runtime context', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $runtimeClass = get_class(new class extends Agent
    {
        public function definition(): AgentDefinition
        {
            return new AgentDefinition(
                name: 'Memory Planner',
                description: 'Uses prior governed experience.',
                responsibilities: ['plan'],
                instructions: 'Use authorized enterprise context and prior governed memory.',
                experts: [],
                requiredContext: ['enterprise'],
                capabilities: [],
            );
        }
    });

    $descriptor = AgentDescriptor::factory()
        ->forRuntimeClass($runtimeClass)
        ->create(['slug' => 'memory-planner']);

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);

    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'actor_id' => $actor->getKey(),
        'status' => AgentExecution::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    AgentEpisodicMemory::factory()->forExecution($execution)->create([
        'topic' => 'planning',
        'objective' => 'Plan the next campaign.',
    ]);

    $provider = new FakeModelProvider(function ($request) {
        expect($request->context)->toHaveKey('memory')
            ->and($request->context['memory']['episodic'])->toHaveCount(1)
            ->and($request->context['memory']['episodic'][0]['provenance']['source_type'])
            ->toBe(AgentExecution::class);

        return new ModelResult(
            text: 'Completed.',
            structured: [
                'answer' => 'Completed.',
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'memory-context-test',
        );
    });

    expect($assignment)->toBeInstanceOf(AgentAssignment::class);
    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Plan the next campaign.',
    ));

    expect($result->succeeded())->toBeTrue();
});