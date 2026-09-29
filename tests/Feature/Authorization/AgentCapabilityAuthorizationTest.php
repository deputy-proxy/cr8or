<?php

use App\Agents\MarketingAgent;
use App\Agents\OperationsAgent;
use App\Agents\ProductAgent;
use App\Experts\MarketingExpert;
use App\Experts\OperationsExpert;
use App\Experts\ProductExpert;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Services\AgentCapabilityAuthorizer;

function authorizationAssignment(string $agentClass, ?Enterprise $enterprise = null): AgentAssignment
{
    return AgentAssignment::factory()
        ->for(AgentDescriptor::factory()->forRuntimeClass($agentClass), 'agentDescriptor')
        ->forEnterprise($enterprise)
        ->create();
}

it('allows an authorized Agent to use a Capability owned by its authorized Expert', function () {
    $enterprise = Enterprise::factory()->create();
    $assignment = authorizationAssignment(MarketingAgent::class, $enterprise);

    expect(app(AgentCapabilityAuthorizer::class)->allowsExpertCapability(
        $assignment,
        'marketing',
        new MarketingExpert,
        'marketing.plan',
        $enterprise->organization,
        $enterprise,
    ))->toBeTrue();
});

it('requires Expert provenance for Agent-backed Capability invocations', function () {
    $enterprise = Enterprise::factory()->create();
    $assignment = authorizationAssignment(MarketingAgent::class, $enterprise);
    $execution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create();
    $actor = \App\Models\User::factory()->create();

    expect(fn () => new \App\Data\CapabilityInvocationRequest(
        capability: 'marketing.plan',
        actor: $actor,
        enterprise: $enterprise,
        assignment: $assignment,
        execution: $execution,
        expertSlug: null,
    ))->toThrow(InvalidArgumentException::class, 'Expert provenance');
});

it('denies an Expert that is not declared by the Agent', function () {
    $enterprise = Enterprise::factory()->create();
    $assignment = authorizationAssignment(OperationsAgent::class, $enterprise);

    expect(app(AgentCapabilityAuthorizer::class)->allowsExpertCapability(
        $assignment,
        'marketing',
        new MarketingExpert,
        'marketing.plan',
        $enterprise->organization,
        $enterprise,
    ))->toBeFalse();
});

it('denies a Capability that the Expert does not own', function () {
    $enterprise = Enterprise::factory()->create();
    $assignment = authorizationAssignment(MarketingAgent::class, $enterprise);

    expect(app(AgentCapabilityAuthorizer::class)->allowsExpertCapability(
        $assignment,
        'marketing',
        new MarketingExpert,
        'work.item.create',
        $enterprise->organization,
        $enterprise,
    ))->toBeFalse();
});

it('denies an Expert runtime substitution even when the Agent is authorized for another Expert', function () {
    $enterprise = Enterprise::factory()->create();
    $assignment = authorizationAssignment(MarketingAgent::class, $enterprise);

    expect(app(AgentCapabilityAuthorizer::class)->allowsExpertCapability(
        $assignment,
        'operations',
        new OperationsExpert,
        'work.item.create',
        $enterprise->organization,
        $enterprise,
    ))->toBeFalse();
});

it('supports shared Capabilities through the specific authorized Expert', function () {
    $enterprise = Enterprise::factory()->create();
    $operations = authorizationAssignment(OperationsAgent::class, $enterprise);
    $product = authorizationAssignment(ProductAgent::class, $enterprise);

    $authorizer = app(AgentCapabilityAuthorizer::class);

    expect($authorizer->allowsExpertCapability(
        $operations,
        'operations',
        new OperationsExpert,
        'work.item.create',
        $enterprise->organization,
        $enterprise,
    ))->toBeTrue()
        ->and($authorizer->allowsExpertCapability(
            $product,
            'product',
            new ProductExpert,
            'work.item.create',
            $enterprise->organization,
            $enterprise,
        ))->toBeTrue();
});

it('enforces organization and enterprise scope through the Expert path', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $enterprise->organization_id]);
    $assignment = authorizationAssignment(MarketingAgent::class, $enterprise);
    $authorizer = app(AgentCapabilityAuthorizer::class);

    expect($authorizer->allowsExpertCapability(
        $assignment,
        'marketing',
        new MarketingExpert,
        'marketing.plan',
        $enterprise->organization,
        $enterprise,
    ))->toBeTrue()
        ->and($authorizer->allowsExpertCapability(
            $assignment,
            'marketing',
            new MarketingExpert,
            'marketing.plan',
            $enterprise->organization,
            $otherEnterprise,
        ))->toBeFalse();
});
