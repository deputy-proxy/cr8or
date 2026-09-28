<?php

use App\Data\CapabilityInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\CapabilityInvocationService;
use Illuminate\Auth\Access\AuthorizationException;

it('invokes a Capability through the application boundary without MCP', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    $result = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'work.item.create',
        actor: $actor,
        enterprise: $enterprise,
        inputPayload: ['name' => 'Application boundary work item'],
        correlationId: 'application-boundary-1',
        idempotencyKey: 'application-boundary-1',
    ));

    expect($result['status'])->toBe('executed')
        ->and($result['capability'])->toBe('work.item.create')
        ->and($result['provenance'])->toMatchArray([
            'capability' => 'work.item.create',
            'operation' => App\Operations\CreateWorkItem::class,
            'enterprise_id' => $enterprise->getKey(),
            'agent_assignment_id' => null,
            'agent_execution_id' => null,
            'correlation_id' => 'application-boundary-1',
        ])
        ->and(WorkItem::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Application boundary work item')->exists())->toBeTrue();
});

it('rejects an application Capability invocation across enterprise authorization boundaries', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $foreignEnterprise = Enterprise::factory()->create();

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'work.item.create',
        actor: $actor,
        enterprise: $foreignEnterprise,
        inputPayload: ['name' => 'Must not persist'],
    )))->toThrow(AuthorizationException::class);

    expect(WorkItem::query()->where('enterprise_id', $foreignEnterprise->getKey())->where('name', 'Must not persist')->exists())->toBeFalse();
});

it('creates a resumable approval request through the application Capability boundary', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->executing()->create([
        'actor_id' => $actor->getKey(),
        'correlation_id' => 'application-approval-1',
    ]);

    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

    $result = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'work.item.create',
        actor: $actor,
        enterprise: $enterprise,
        assignment: $assignment,
        execution: $execution,
        inputPayload: ['name' => 'Approval gated work item'],
        correlationId: 'application-approval-1',
        idempotencyKey: 'application-approval-1',
    ));

    expect($result['status'])->toBe('waiting')
        ->and($result['approval']->capability)->toBe('work.item.create')
        ->and($result['approval']->agent_execution_id)->toBe($execution->getKey())
        ->and(WorkItem::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Approval gated work item')->exists())->toBeFalse();
});

it('preserves the canonical Capability operation mapping for Agent-backed invocation', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->executing()->create([
        'actor_id' => $actor->getKey(),
        'correlation_id' => 'application-agent-1',
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

    $result = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'work.item.create',
        actor: $actor,
        enterprise: $enterprise,
        assignment: $assignment,
        execution: $execution,
        inputPayload: ['name' => 'Agent boundary work item'],
        correlationId: 'application-agent-1',
        idempotencyKey: 'application-agent-1',
    ));

    expect($result['status'])->toBe('executed')
        ->and($result['provenance']['operation'])->toBe(App\Operations\CreateWorkItem::class)
        ->and($result['provenance']['agent_assignment_id'])->toBe($assignment->getKey())
        ->and($result['provenance']['agent_execution_id'])->toBe($execution->getKey());
});