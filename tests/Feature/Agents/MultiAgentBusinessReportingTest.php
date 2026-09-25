<?php

use App\Agents\FinanceAgent;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\FinancialReport;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Services\MultiAgentBusinessReportingService;
use Illuminate\Auth\Access\AuthorizationException;

it('builds an Enterprise-scoped multi-Agent report from authoritative records without mutation', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'agent_slug' => 'finance',
        'agent_runtime_class' => 'App\\Agents\\FinanceAgent',
    ]);
    $execution->start()->succeed()->save();

    $targetDescriptor = AgentDescriptor::factory()->create([
        'runtime_class' => FinanceAgent::class,
        'slug' => 'finance-target',
    ]);
    $targetAssignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $targetDescriptor->getKey(),
    ]);
    $delegation = AgentDelegation::factory()->succeeded()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'source_agent_assignment_id' => $assignment->getKey(),
        'target_agent_assignment_id' => $targetAssignment->getKey(),
        'parent_agent_execution_id' => $execution->getKey(),
        'source_agent_slug' => 'ceo',
        'target_agent_slug' => 'finance',
        'source_agent_runtime_class' => 'App\\Agents\\CeoAgent',
        'target_agent_runtime_class' => 'App\\Agents\\FinanceAgent',
    ]);

    $failedExecution = AgentExecution::factory()->forAssignment($assignment)->create([
        'agent_slug' => 'finance',
    ]);
    $failedExecution->fail('Provider unavailable')->save();

    $failedWorkflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'status' => Workflow::STATUS_FAILED,
    ]);

    $approver = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $approver,
        'organization_id' => $enterprise->organization_id,
    ]);

    $approvalData = [
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => null,
        'actor_id' => $user->getKey(),
        'approver_id' => null,
        'capability' => 'work.item.update',
        'target_context' => ['resource_id' => 123],
        'organization_name' => $enterprise->organization->name,
        'enterprise_name' => $enterprise->name,
        'agent_slug' => $assignment->agentDescriptor->slug,
        'agent_runtime_class' => $assignment->agentDescriptor->runtime_class,
        'actor_name' => $user->name,
        'approver_name' => null,
        'status' => ApprovalRequest::STATUS_PENDING,
        'requested_at' => now(),
        'expires_at' => now()->addHour(),
        'decided_at' => null,
        'decision_reason' => null,
    ];

    $approved = ApprovalRequest::query()->create($approvalData);
    $approved->approve($approver, 'Approved for execution')->save();

    $rejected = ApprovalRequest::query()->create($approvalData);
    $rejected->reject($approver, 'Rejected by owner')->save();

    $report = app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);

    expect($report['execution_summary'])->toMatchArray([
        'requested' => 0,
        'executing' => 0,
        'succeeded' => 1,
        'failed' => 1,
        'total' => 2,
    ])->and($report['delegation_summary'])->toMatchArray([
        'pending' => 0,
        'running' => 0,
        'succeeded' => 1,
        'failed' => 0,
        'total' => 1,
    ])->and($report['workflow_summary'])->toMatchArray([
        'pending' => 0,
        'running' => 0,
        'succeeded' => 0,
        'failed' => 1,
        'total' => 1,
    ])->and($report['approval_summary'])->toMatchArray([
        'pending' => 0,
        'approved' => 1,
        'rejected' => 1,
        'total' => 2,
    ])->and($report['failed_operations'])->toHaveCount(1)
        ->and($report['failed_workflows'])->toHaveCount(1)
        ->and($report['failed_workflows'][0])->toMatchArray([
            'id' => $failedWorkflow->getKey(),
            'status' => Workflow::STATUS_FAILED,
        ])
        ->and($report['approval_outcomes'][0])->toMatchArray([
            'status' => ApprovalRequest::STATUS_APPROVED,
            'capability' => $approved->capability,
            'approver_name' => $approver->name,
            'decision_reason' => 'Approved for execution',
        ])
        ->and($report['approval_outcomes'][1])->toMatchArray([
            'status' => ApprovalRequest::STATUS_REJECTED,
            'capability' => $rejected->capability,
            'approver_name' => $approver->name,
            'decision_reason' => 'Rejected by owner',
        ])
        ->and($report['business_results'])->toMatchArray([
            'financial_reports' => [],
            'business_health_results' => [],
        ]);

    expect($report['agent_activity'][0]['agent_slug'])->toBe('ceo')
        ->and($report['agent_activity'][0]['delegations_out'])->toBe(1)
        ->and($report['agent_activity'][1]['agent_slug'])->toBe('finance')
        ->and($report['agent_activity'][1]['execution_count'])->toBe(2)
        ->and($report['agent_activity'][1]['executions_failed'])->toBe(1);

    $executionSnapshot = $execution->only(['id', 'status', 'failure_reason', 'agent_slug']);
    $delegationSnapshot = $delegation->only(['id', 'status', 'failure_reason', 'source_agent_slug', 'target_agent_slug']);

    app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);

    expect($execution->refresh()->only(['id', 'status', 'failure_reason', 'agent_slug']))->toBe($executionSnapshot)
        ->and($delegation->refresh()->only(['id', 'status', 'failure_reason', 'source_agent_slug', 'target_agent_slug']))->toBe($delegationSnapshot);
});

it('denies cross-organization report access', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    expect(fn () => app(MultiAgentBusinessReportingService::class)->generate($user, $foreignEnterprise))
        ->toThrow(AuthorizationException::class);
});

it('keeps report output deterministic and uses historical Agent identity', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'agent_slug' => 'historical-finance',
        'agent_runtime_class' => 'App\\Agents\\FinanceAgent',
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);

    $first = app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);
    $second = app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);

    expect($first)->toBe($second);

    $assignment->update(['enabled' => false]);

    $third = app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);

    expect($third['agent_activity'][0]['agent_slug'])->toBe('historical-finance')
        ->and($third['agent_activity'][0]['runtime_class'])->toBe('App\\Agents\\FinanceAgent');
});

it('does not expose another Enterprise records in an authorized report', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);

    $localAssignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    AgentExecution::factory()->forAssignment($localAssignment)->create();

    AgentExecution::factory()->create([
        'organization_id' => $foreignEnterprise->organization_id,
        'enterprise_id' => $foreignEnterprise->getKey(),
        'agent_descriptor_id' => $localAssignment->agent_descriptor_id,
        'agent_assignment_id' => null,
        'organization_name' => $foreignEnterprise->organization->name,
        'enterprise_name' => $foreignEnterprise->name,
    ]);

    $report = app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);

    expect($report['execution_summary']['total'])->toBe(1);
});

it('includes existing financial report and business health references without rewriting them', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);

    $financialReport = FinancialReport::factory()->create(['enterprise_id' => $enterprise]);
    $health = BusinessHealthResult::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_report_id' => $financialReport,
    ]);

    $report = app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise);

    expect($report['business_results']['financial_reports'][0]['id'])->toBe($financialReport->getKey())
        ->and($report['business_results']['business_health_results'][0])->toMatchArray([
            'id' => $health->getKey(),
            'financial_report_id' => $financialReport->getKey(),
            'health_status' => $health->health_status,
        ]);
});