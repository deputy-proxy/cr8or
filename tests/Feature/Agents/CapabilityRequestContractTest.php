<?php

use App\Agents\OperationsAgent;
use App\Data\CapabilityRequest;
use App\Experts\OperationsExpert;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\User;

function capabilityRequestContractSetup(): array
{
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $agentDescriptor = AgentDescriptor::factory()
        ->forRuntimeClass(OperationsAgent::class)
        ->create(['slug' => 'operations']);
    ExpertDescriptor::factory()
        ->forRuntimeClass(OperationsExpert::class)
        ->create(['slug' => 'operations']);

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $agentDescriptor->getKey(),
    ]);

    $execution = AgentExecution::factory()->forAssignment($assignment)->executing()->create([
        'actor_id' => $actor->getKey(),
        'correlation_id' => 'capability-contract-123',
    ]);

    return [$actor, $assignment, $execution, $enterprise];
}

it('defines the canonical Capability Request fields and serialization', function () {
    [$actor, $assignment, $execution, $enterprise] = capabilityRequestContractSetup();

    $request = new CapabilityRequest(
        capability: 'work.item.create',
        assignment: $assignment,
        execution: $execution,
        actor: $actor,
        targetContext: ['enterprise_id' => $enterprise->getKey(), 'resource' => 'work'],
        inputPayload: ['name' => 'Contract test work item'],
        expertSlug: 'operations',
        correlationId: 'capability-contract-123',
        idempotencyKey: 'capability-request-123',
    );

    expect($request->resolvedCorrelationId())->toBe('capability-contract-123')
        ->and($request->toArray())->toMatchArray([
            'capability' => 'work.item.create',
            'target_context' => ['enterprise_id' => $enterprise->getKey(), 'resource' => 'work'],
            'input_payload' => ['name' => 'Contract test work item'],
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
            'expert_slug' => 'operations',
            'correlation_id' => 'capability-contract-123',
            'idempotency_key' => 'capability-request-123',
            'approval_request_id' => null,
            'delegation_id' => null,
        ]);
});

it('rejects a Capability Request that is not scoped to its Agent execution', function () {
    [$actor, $assignment, $execution] = capabilityRequestContractSetup();
    $otherAssignment = AgentAssignment::factory()->forEnterprise(Enterprise::factory()->create())->for($assignment->agentDescriptor, 'agentDescriptor')->create();

    expect(fn () => new CapabilityRequest(
        capability: 'work.item.create',
        assignment: $otherAssignment,
        execution: $execution,
        actor: $actor,
        targetContext: [],
        inputPayload: [],
        expertSlug: 'operations',
    ))->toThrow(InvalidArgumentException::class, 'must belong to its Agent assignment');
});

it('rejects a Capability Request with a correlation identifier from another execution', function () {
    [$actor, $assignment, $execution] = capabilityRequestContractSetup();

    expect(fn () => new CapabilityRequest(
        capability: 'work.item.create',
        assignment: $assignment,
        execution: $execution,
        actor: $actor,
        targetContext: [],
        inputPayload: [],
        expertSlug: 'operations',
        correlationId: 'different-execution',
    ))->toThrow(InvalidArgumentException::class, 'must match its Agent execution');
});

it('keeps Capability authorization separate from Agent assignment metadata', function () {
    [$actor, $assignment, $execution] = capabilityRequestContractSetup();

    $request = new CapabilityRequest(
        capability: 'work.item.create',
        assignment: $assignment,
        execution: $execution,
        actor: $actor,
        targetContext: [],
        inputPayload: [],
        expertSlug: 'operations',
    );

    $authorizer = app(\App\Services\AgentCapabilityAuthorizer::class);

    expect($authorizer->allowsRequest($request))->toBeTrue();

    $unpermittedRequest = new CapabilityRequest(
        capability: 'strategy.create',
        assignment: $assignment,
        execution: $execution,
        actor: $actor,
        targetContext: [],
        inputPayload: [],
        expertSlug: 'operations',
    );

    expect($authorizer->allowsRequest($unpermittedRequest))->toBeFalse();
});