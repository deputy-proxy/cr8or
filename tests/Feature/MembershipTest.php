<?php

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

it('allows a user to belong to an organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $membership = Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $organization,
        'role' => MembershipRole::Member,
    ]);

    expect($user->memberships)->toHaveCount(1)
        ->and($user->memberships->first()->is($membership))->toBeTrue()
        ->and($user->organizations->first()->is($organization))->toBeTrue();
});

it('allows an organization to retrieve its members', function () {
    $organization = Organization::factory()->create();
    $users = User::factory()->count(2)->create();

    Membership::factory()->create([
        'user_id' => $users[0],
        'organization_id' => $organization,
    ]);
    Membership::factory()->create([
        'user_id' => $users[1],
        'organization_id' => $organization,
    ]);

    expect($organization->members)->toHaveCount(2)
        ->and($organization->members->pluck('id')->sort()->values()->all())
        ->toBe($users->pluck('id')->sort()->values()->all());
});

it('rejects duplicate memberships for the same user and organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    expect(fn () => Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]))->toThrow(QueryException::class);
});

it('allows a user to belong to multiple organizations', function () {
    $user = User::factory()->create();
    $organizations = Organization::factory()->count(2)->create();

    foreach ($organizations as $organization) {
        Membership::factory()->create([
            'user_id' => $user,
            'organization_id' => $organization,
        ]);
    }

    expect($user->memberships)->toHaveCount(2)
        ->and($user->organizations)->toHaveCount(2);
});

it('persists and casts the membership role', function () {
    $membership = Membership::factory()->admin()->create();

    $membership->refresh();

    expect($membership->role)->toBe(MembershipRole::Admin);
});

it('allows owners to manage memberships and members to view them', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    $memberMembership = Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $organization,
    ]);

    expect(Gate::forUser($owner)->allows('update', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $memberMembership))->toBeFalse();
});

it('allows admins to manage members but not privileged memberships', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $otherAdmin = User::factory()->create();

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

    expect(Gate::forUser($admin)->allows('update', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $adminMembership))->toBeFalse();
});

it('denies membership management across organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    $foreignMembership = Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $otherOrganization,
    ]);

    expect(Gate::forUser($owner)->allows('update', $foreignMembership))->toBeFalse();
});