<?php

use App\Filament\Resources\AgentAssignments\AgentAssignmentResource;
use App\Filament\Resources\AgentPermissions\AgentPermissionResource;
use App\Filament\Resources\Enterprises\EnterpriseResource;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('allows organization owners and admins to see organization-scoped create actions', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->admin()->create(['user_id' => $admin, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $resources = [
        EnterpriseResource::class,
        MembershipResource::class,
        AgentAssignmentResource::class,
        AgentPermissionResource::class,
    ];

    $this->actingAs($owner);
    foreach ($resources as $resource) {
        expect($resource::canCreate())->toBeTrue();
    }

    $this->actingAs($admin);
    foreach ($resources as $resource) {
        expect($resource::canCreate())->toBeTrue();
    }

    $this->actingAs($member);
    foreach ($resources as $resource) {
        expect($resource::canCreate())->toBeFalse();
    }
});
