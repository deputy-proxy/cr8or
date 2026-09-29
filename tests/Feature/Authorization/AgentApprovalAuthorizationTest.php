<?php

use App\Agents\MarketingAgent;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\ApprovalRequestService;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;

function approvalAuthorizationContext(): array
{
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    $agentDescriptor = AgentDescriptor::query()->firstOrCreate(
        ['runtime_class' => MarketingAgent::class],
        ['slug' => 'marketing-agent', 'enabled' => true],
    );

    $assignment = AgentAssignment::factory()
        ->for($agentDescriptor, 'agentDescriptor')
        ->forEnterprise($enterprise)
        ->create();

    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'marketing'],
        ['runtime_class' => \App\Experts\MarketingExpert::class, 'enabled' => true],
    );

    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $enterprise->organization_id,
    ]);

    return [$enterprise, $actor, $assignment];
}

it('allows an Expert-owned approval-required Capability only after approval', function () {
    [$enterprise, $actor, $assignment] = approvalAuthorizationContext();
    $authorizer = app(AgentCapabilityAuthorizer::class);
    $execution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey()]);

    expect($authorizer->allowsExpertCapability(
        $assignment,
        'marketing',
        new \App\Experts\MarketingExpert,
        'marketing.content.publication-ready',
        $enterprise->organization,
        $enterprise,
        $actor,
        null,
        $execution,
        ['content_item_id' => 1],
    ))->toBeFalse();

    $approval = app(ApprovalRequestService::class)->request(
        $actor,
        'marketing.content.publication-ready',
        $assignment,
        $execution,
        ['content_item_id' => 1],
        null,
        'marketing',
    );

    expect($authorizer->allowsExpertCapability(
        $assignment,
        'marketing',
        new \App\Experts\MarketingExpert,
        'marketing.content.publication-ready',
        $enterprise->organization,
        $enterprise,
        $actor,
        $approval,
        $execution,
        ['content_item_id' => 1],
    ))->toBeFalse();

    $approver = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $approver,
        'organization_id' => $enterprise->organization_id,
    ]);
    app(ApprovalRequestService::class)->approve($approval, $approver, 'Approved');

    expect($approval->refresh()->expert_slug)->toBe('marketing')
        ->and($authorizer->allowsExpertCapability(
            $assignment,
            'marketing',
            new \App\Experts\MarketingExpert,
            'marketing.content.publication-ready',
            $enterprise->organization,
            $enterprise,
            $actor,
            $approval,
            $execution,
            ['content_item_id' => 1],
        ))->toBeTrue();
});

it('rejects approval requests without Expert provenance', function () {
    [$enterprise, $actor, $assignment] = approvalAuthorizationContext();
    $execution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey()]);

    expect(fn () => app(McpCapabilityAuthorizer::class)->authorizeApprovalRequest(
        $actor,
        $assignment,
        $execution,
        'marketing.content.publication-ready',
    ))->toThrow(AuthorizationException::class, 'Expert provenance');
});

it('does not use AgentPermission as an approval authorization mechanism', function () {
    [$enterprise, $actor, $assignment] = approvalAuthorizationContext();
    $execution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey()]);

    expect(app(McpCapabilityAuthorizer::class)->authorizeApprovalRequest(
        $actor,
        $assignment,
        $execution,
        'marketing.content.publication-ready',
        'marketing',
    ))->toBeNull();
});

it('rejects an Expert that the Agent is not authorized to invoke', function () {
    [$enterprise, $actor, $assignment] = approvalAuthorizationContext();
    $execution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey()]);

    expect(fn () => app(McpCapabilityAuthorizer::class)->authorizeApprovalRequest(
        $actor,
        $assignment,
        $execution,
        'finance.report.generate',
        'finance',
    ))->toThrow(AuthorizationException::class);
});
