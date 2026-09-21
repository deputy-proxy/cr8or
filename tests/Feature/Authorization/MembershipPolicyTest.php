<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('denies membership access to users without membership', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $target = User::factory()->create();

    $membership = Membership::factory()->create([
        'user_id' => $target,
        'organization_id' => $organization,
    ]);

    expect(Gate::forUser($user)->allows('view', $membership))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $membership))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $membership))->toBeFalse();
});

it('denies membership access across organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $foreignMember = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    $foreignMembership = Membership::factory()->create([
        'user_id' => $foreignMember,
        'organization_id' => $otherOrganization,
    ]);

    expect(Gate::forUser($owner)->allows('view', $foreignMembership))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $foreignMembership))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $foreignMembership))->toBeFalse();
});

it('allows owners and admins to create memberships only in their organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
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

    expect(Gate::forUser($owner)->allows('create', [Membership::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Membership::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Membership::class, $organization]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [Membership::class, $otherOrganization]))->toBeFalse();
});

it('enforces membership mutation roles', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $otherAdmin = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    Membership::factory()->admin()->create([
        'user_id' => $admin,
        'organization_id' => $organization,
    ]);
    $memberMembership = Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $organization,
    ]);
    $adminMembership = Membership::factory()->admin()->create([
        'user_id' => $otherAdmin,
        'organization_id' => $organization,
    ]);

    expect(Gate::forUser($owner)->allows('update', $adminMembership))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $adminMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $adminMembership))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $adminMembership))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $memberMembership))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $memberMembership))->toBeFalse();
});
