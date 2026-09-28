<?php

use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDelegation;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\EnterpriseDecision;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\McpContextAssembler;

it('assembles bounded decision history with historical identity snapshots', function () {
    $user = User::factory()->create(['name' => 'Current User']);
    $organization = Organization::factory()->create(['name' => 'Current Organization']);
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'Current Enterprise']);
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise]);

    $decision = Decision::factory()->by($user)->create([
        'enterprise_id' => $enterprise,
        'work_item_id' => App\Models\WorkItem::factory()->create(['enterprise_id' => $enterprise])->getKey(),
        'actor_name' => 'Historical User',
    ]);
    $enterpriseDecision = EnterpriseDecision::factory()->create([
        'enterprise_id' => $enterprise,
        'actor_name' => 'Historical Enterprise Actor',
    ]);
    $agentDecision = AgentDecision::factory()->create([
        'organization_id' => $organization,
        'enterprise_id' => $enterprise,
        'organization_name' => 'Historical Organization',
        'enterprise_name' => 'Historical Enterprise',
        'agent_slug' => 'historical-agent',
        'actor_name' => 'Historical Agent Actor',
    ]);

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise', 'decisions'],
        ['work_item_id' => $decision->work_item_id],
    );

    $data = $context->section('decisions')?->data;

    expect($data['decision_records'])->toHaveCount(1)
        ->and($data['decision_records'][0]['id'])->toBe($decision->getKey())
        ->and($data['decision_records'][0]['actor_name'])->toBe('Historical User')
        ->and($data['enterprise_decisions'][0]['id'])->toBe($enterpriseDecision->getKey())
        ->and($data['enterprise_decisions'][0]['actor_name'])->toBe('Historical Enterprise Actor')
        ->and($data['agent_decisions'][0]['id'])->toBe($agentDecision->getKey())
        ->and($data['agent_decisions'][0]['enterprise_name'])->toBe('Historical Enterprise');
});

it('keeps execution history scoped to the organization and current Agent assignment', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $otherDescriptor = AgentDescriptor::factory()->forRuntimeClass(App\Agents\CeoAgent::class)->create();
    $otherAssignment = AgentAssignment::query()->create([
        'agent_descriptor_id' => $otherDescriptor->getKey(),
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'enabled' => true,
        'status' => AgentAssignment::STATUS_DRAFT,
    ]);

    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'agent_slug' => 'historical-agent',
        'enterprise_name' => 'Historical Enterprise',
        'actor_name' => 'Historical Actor',
    ]);
    AgentExecution::factory()->forAssignment($otherAssignment)->create();
    AgentExecution::factory()->forEnterprise($foreignEnterprise)->create();

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise', 'execution_history'],
        [],
        $assignment,
    );

    $executions = $context->section('execution_history')?->data['executions'];

    expect($executions)->toHaveCount(1)
        ->and($executions[0]['id'])->toBe($execution->getKey())
        ->and($executions[0]['agent_slug'])->toBe('historical-agent')
        ->and($executions[0]['enterprise_name'])->toBe('Historical Enterprise');
});

it('preserves failed executions and related approval outcomes as history', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $otherDescriptor = AgentDescriptor::factory()->forRuntimeClass(App\Agents\CeoAgent::class)->create();
    $otherAssignment = AgentAssignment::query()->create([
        'agent_descriptor_id' => $otherDescriptor->getKey(),
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'enabled' => true,
        'status' => AgentAssignment::STATUS_DRAFT,
    ]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create();
    $execution->start()->fail('Provider unavailable')->save();

    $delegation = AgentDelegation::factory()->forParentExecution($execution)->state(['target_agent_assignment_id' => $otherAssignment->getKey(), 'actor_id' => $user->getKey(), 'actor_name' => $user->name])->failed('Delegation provider unavailable')->create();
    $approval = ApprovalRequest::query()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'agent_delegation_id' => $delegation->getKey(),
        'actor_id' => $user->getKey(),
        'approver_id' => $user->getKey(),
        'capability' => 'work.update',
        'target_context' => ['work_item_id' => 1],
        'organization_name' => $enterprise->organization->name,
        'enterprise_name' => $enterprise->name,
        'agent_slug' => $assignment->agentDescriptor->slug,
        'agent_runtime_class' => $assignment->agentDescriptor->runtime_class,
        'actor_name' => $user->name,
        'approver_name' => $user->name,
        'status' => ApprovalRequest::STATUS_REJECTED,
        'requested_at' => now()->subMinute(),
        'expires_at' => now()->addHour(),
        'decided_at' => now(),
        'decision_reason' => 'Rejected by operator',
    ]);

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise', 'execution_history'],
        [],
        $assignment,
    );

    $data = $context->section('execution_history')?->data;

    expect($data['executions'][0]['status'])->toBe(AgentExecution::STATUS_FAILED)
        ->and($data['executions'][0]['failure_reason'])->toBe('Provider unavailable')
        ->and($data['delegations'][0]['status'])->toBe(AgentDelegation::STATUS_FAILED)
        ->and($data['delegations'][0]['failure_reason'])->toBe('Delegation provider unavailable')
        ->and($data['approvals'][0]['id'])->toBe($approval->getKey())
        ->and($data['approvals'][0]['status'])->toBe(ApprovalRequest::STATUS_REJECTED)
        ->and($data['approvals'][0]['decision_reason'])->toBe('Rejected by operator');
});

it('bounds each historical source to the configured limit', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();

    Decision::factory()->count(30)->create(['enterprise_id' => $enterprise]);
    EnterpriseDecision::factory()->count(30)->create(['enterprise_id' => $enterprise]);
    $executionForDecisions = AgentExecution::factory()->forAssignment($assignment)->create();
    for ($i = 0; $i < 30; $i++) {
        AgentDecision::factory()->forExecution($executionForDecisions)->create();
    }
    AgentExecution::factory()->count(30)->forAssignment($assignment)->create();

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise', 'decisions', 'execution_history'],
        [],
        $assignment,
    );

    $data = $context->toArray();

    expect($data['decisions']['decision_records'])->toHaveCount(25)
        ->and($data['decisions']['enterprise_decisions'])->toHaveCount(25)
        ->and($data['decisions']['agent_decisions'])->toHaveCount(25)
        ->and($data['execution_history']['executions'])->toHaveCount(25);
});

it('does not expose history from another organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);

    Decision::factory()->create(['enterprise_id' => $foreignEnterprise]);
    EnterpriseDecision::factory()->create(['enterprise_id' => $foreignEnterprise]);
    AgentExecution::factory()->forEnterprise($foreignEnterprise)->create();

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise', 'decisions', 'execution_history'],
    );

    expect($context->section('decisions')?->data['decision_records'])->toBe([])
        ->and($context->section('decisions')?->data['enterprise_decisions'])->toBe([])
        ->and($context->section('execution_history')?->data['executions'])->toBe([]);
});
