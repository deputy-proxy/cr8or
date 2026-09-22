<?php

use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Services\AgentCapabilityAuthorizer;

it('allows an assigned enabled agent to request an explicitly permitted capability', function () {
    $organization = Organization::factory()->create();
    $assignment = AgentAssignment::factory()->create([
        'organization_id' => $organization,
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows(
        $assignment,
        'marketing.plan',
        $organization,
    ))->toBeTrue();
});

it('denies a missing capability', function () {
    $assignment = AgentAssignment::factory()->create();
    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows($assignment, 'finance.execute'))->toBeFalse();
});

it('denies a disabled agent descriptor', function () {
    $assignment = AgentAssignment::factory()
        ->for(\App\Models\AgentDescriptor::factory()->disabled(), 'agentDescriptor')
        ->create();

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows($assignment, 'marketing.plan'))->toBeFalse();
});

it('denies a disabled assignment', function () {
    $assignment = AgentAssignment::factory()->disabled()->create();

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows($assignment, 'marketing.plan'))->toBeFalse();
});

it('denies capability access across organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $assignment = AgentAssignment::factory()->create([
        'organization_id' => $organization,
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows(
        $assignment,
        'marketing.plan',
        $otherOrganization,
    ))->toBeFalse();
});

it('enforces enterprise scope', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization,
    ]);
    $otherEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization,
    ]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    $authorizer = app(AgentCapabilityAuthorizer::class);

    expect($authorizer->allows($assignment, 'marketing.plan', $organization, $enterprise))->toBeTrue()
        ->and($authorizer->allows($assignment, 'marketing.plan', $organization, $otherEnterprise))->toBeFalse()
        ->and($authorizer->allows($assignment, 'marketing.plan', $organization))->toBeFalse();
});

it('denies an enterprise from another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $otherOrganization,
    ]);
    $assignment = AgentAssignment::factory()->create([
        'organization_id' => $organization,
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows(
        $assignment,
        'marketing.plan',
        $organization,
        $enterprise,
    ))->toBeFalse();
});

it('does not inherit expert capabilities during delegation', function () {
    $assignment = AgentAssignment::factory()->create();

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'marketing.plan',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows(
        $assignment,
        'finance.execute',
    ))->toBeFalse();
});
