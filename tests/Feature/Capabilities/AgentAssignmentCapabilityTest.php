<?php

use App\Data\CapabilityInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\CapabilityExecutionException;
use App\Services\CapabilityInvocationService;

function assignmentCapabilityActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->admin()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $agent = AgentDescriptor::factory()->create(['enabled' => true]);

    return [$user, $enterprise, $agent];
}

it('creates, updates and transitions an Agent Assignment through Capabilities without MCP', function (): void {
    [$user, $enterprise, $agent] = assignmentCapabilityActor();

    $created = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_descriptor_id' => $agent->getKey(),
            'objective' => 'Run the quarterly planning workflow.',
            'requirements' => ['source' => 'approved-data'],
            'context' => ['period' => 'Q4'],
            'idempotency_key' => 'assignment-capability-1',
            'correlation_id' => 'assignment-capability-1',
        ],
        correlationId: 'assignment-capability-1',
        idempotencyKey: 'assignment-capability-1',
    ));

    expect($created['status'])->toBe('executed')
        ->and($created['provenance'])->toMatchArray([
            'capability' => 'agent.assignment.create',
            'operation' => App\Operations\CreateAgentAssignment::class,
            'enterprise_id' => $enterprise->getKey(),
            'correlation_id' => 'assignment-capability-1',
        ]);

    $assignmentId = $created['result']['id'];

    expect(AgentAssignment::query()->whereKey($assignmentId)
        ->where('enterprise_id', $enterprise->getKey())
        ->where('agent_descriptor_id', $agent->getKey())
        ->where('status', AgentAssignment::STATUS_DRAFT)
        ->exists())->toBeTrue();

    $updated = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.update',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_assignment_id' => $assignmentId,
            'objective' => 'Run the quarterly planning workflow with approved data.',
        ],
        correlationId: 'assignment-capability-2',
    ));

    expect($updated['status'])->toBe('executed')
        ->and($updated['result']['objective'])->toContain('approved data');

    $ready = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.transition',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_assignment_id' => $assignmentId,
            'status' => AgentAssignment::STATUS_READY,
        ],
    ));

    $running = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.transition',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_assignment_id' => $assignmentId,
            'status' => AgentAssignment::STATUS_RUNNING,
        ],
    ));

    $assignment = AgentAssignment::query()->findOrFail($assignmentId);

    expect($ready['result']['status'])->toBe(AgentAssignment::STATUS_READY)
        ->and($running['result']['status'])->toBe(AgentAssignment::STATUS_RUNNING)
        ->and($assignment->started_at)->not->toBeNull()
        ->and($running['result']['agent']['id'])->toBe($agent->getKey());
});

it('returns the existing Assignment for a repeated idempotent create', function (): void {
    [$user, $enterprise, $agent] = assignmentCapabilityActor();

    $input = [
        'agent_descriptor_id' => $agent->getKey(),
        'objective' => 'Idempotent assignment.',
        'idempotency_key' => 'assignment-repeat-1',
    ];

    $first = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: $input,
        idempotencyKey: 'assignment-repeat-1',
    ));

    $second = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: $input,
        idempotencyKey: 'assignment-repeat-1',
    ));

    expect($second['result']['id'])->toBe($first['result']['id'])
        ->and(AgentAssignment::query()->where('enterprise_id', $enterprise->getKey())->where('idempotency_key', 'assignment-repeat-1')->count())->toBe(1);
});

it('retains canonical Operation failure provenance when a lifecycle Operation rejects input', function (): void {
    [$user, $enterprise, $agent] = assignmentCapabilityActor();

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $agent->getKey(),
        'status' => AgentAssignment::STATUS_DRAFT,
    ]);

    try {
        app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
            capability: 'agent.assignment.transition',
            actor: $user,
            enterprise: $enterprise,
            inputPayload: [
                'agent_assignment_id' => $assignment->getKey(),
                'status' => AgentAssignment::STATUS_RUNNING,
            ],
        ));

        throw new RuntimeException('Expected capability execution failure.');
    } catch (CapabilityExecutionException $exception) {
        expect($exception->failure->provenance->toArray())->toMatchArray([
            'operation' => App\Operations\TransitionAgentAssignment::class,
            'capability' => 'agent.assignment.transition',
        ])->and($exception->failure->code)->toBe(App\AI\Contracts\FailureCode::VALIDATION_FAILED);
    }
});

it('rejects invalid lifecycle transitions and disabled Agents', function (): void {
    [$user, $enterprise, $agent] = assignmentCapabilityActor();

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $agent->getKey(),
        'status' => AgentAssignment::STATUS_DRAFT,
    ]);

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.transition',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_assignment_id' => $assignment->getKey(),
            'status' => AgentAssignment::STATUS_RUNNING,
        ],
    )))->toThrow(CapabilityExecutionException::class);

    $disabledAgent = $agent->refresh();
    $disabledAgent->update(['enabled' => false]);

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_descriptor_id' => $disabledAgent->getKey(),
            'objective' => 'Must not create.',
        ],
    )))->toThrow(CapabilityExecutionException::class);
});

it('rejects cross-enterprise Assignment relationships and unauthorized users', function (): void {
    [$user, $enterprise, $agent] = assignmentCapabilityActor();
    $foreign = Enterprise::factory()->create();
    $foreignAssignment = AgentAssignment::factory()->forEnterprise($foreign)->create([
        'agent_descriptor_id' => $agent->getKey(),
    ]);

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.update',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'agent_assignment_id' => $foreignAssignment->getKey(),
            'objective' => 'Must not cross the Enterprise boundary.',
        ],
    )))->toThrow(CapabilityExecutionException::class);

    $member = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $member->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'agent.assignment.create',
        actor: $member,
        enterprise: $enterprise,
        inputPayload: [
            'agent_descriptor_id' => $agent->getKey(),
            'objective' => 'Must not be created by a member.',
        ],
    )))->toThrow(CapabilityExecutionException::class);
});