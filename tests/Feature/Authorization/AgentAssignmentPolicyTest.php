<?php

use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows organization owners and admins to manage agent assignments', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    Membership::factory()->admin()->create([
        'user_id' => $admin,
        'organization_id' => $organization,
    ]);
    Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $organization,
    ]);

    $assignment = AgentAssignment::factory()->create([
        'organization_id' => $organization,
    ]);

    expect(Gate::forUser($owner)->allows('view', $assignment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', $assignment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $assignment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $assignment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $assignment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', $assignment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $assignment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $assignment))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $assignment))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', $assignment))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $assignment))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $assignment))->toBeFalse();
});

it('denies agent assignment management across organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);

    $assignment = AgentAssignment::factory()->create([
        'organization_id' => $otherOrganization,
    ]);

    expect(Gate::forUser($owner)->allows('view', $assignment))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $assignment))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $assignment))->toBeFalse();
});

it('denies assignments whose enterprise is outside the organization scope', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $otherOrganization,
    ]);

    $assignment = AgentAssignment::factory()->create([
        'organization_id' => $organization,
        'enterprise_id' => $enterprise->id,
    ]);

    expect(Gate::forUser($owner)->allows('view', $assignment))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $assignment))->toBeFalse();
});

it('allows valid enterprise-scoped assignments for administrators', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization,
    ]);
    $admin = User::factory()->create();

    Membership::factory()->admin()->create([
        'user_id' => $admin,
        'organization_id' => $organization,
    ]);

    $assignment = AgentAssignment::factory()->create([
        'agent_descriptor_id' => AgentDescriptor::factory(),
        'organization_id' => $organization,
        'enterprise_id' => $enterprise,
    ]);

    expect(Gate::forUser($admin)->allows('update', $assignment))->toBeTrue();
});
