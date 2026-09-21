<?php

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows an authenticated user to create an organization', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('create', Organization::class))->toBeTrue();
});

it('allows organization members to view their organization', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);

    expect(Gate::forUser($owner)->allows('view', $organization))->toBeTrue();
});

it('denies organization access to users without membership', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('view', $organization))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $organization))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $organization))->toBeFalse();
});

it('denies organization access across organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);

    expect(Gate::forUser($owner)->allows('view', $otherOrganization))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $otherOrganization))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $otherOrganization))->toBeFalse();
});

it('enforces organization role capabilities', function () {
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

    expect(Gate::forUser($owner)->allows('update', $organization))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $organization))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $organization))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $organization))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $organization))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $organization))->toBeFalse();
});

it('allows every membership role to view its organization', function (MembershipRole $role) {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $organization,
        'role' => $role,
    ]);

    expect(Gate::forUser($user)->allows('view', $organization))->toBeTrue();
})->with([
    MembershipRole::Owner,
    MembershipRole::Admin,
    MembershipRole::Member,
]);
