<?php

use App\Filament\Resources\Users\UserResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('limits the user resource to users sharing an organization with the current user', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $currentUser = User::factory()->create();
    $sameOrganizationUser = User::factory()->create();
    $otherOrganizationUser = User::factory()->create();

    Membership::factory()->create(['user_id' => $currentUser->id, 'organization_id' => $organizationA->id]);
    Membership::factory()->create(['user_id' => $sameOrganizationUser->id, 'organization_id' => $organizationA->id]);
    Membership::factory()->create(['user_id' => $otherOrganizationUser->id, 'organization_id' => $organizationB->id]);

    $this->actingAs($currentUser);

    $visibleUserIds = UserResource::getEloquentQuery()->pluck('users.id')->all();

    expect($visibleUserIds)->toContain($currentUser->id, $sameOrganizationUser->id)
        ->not->toContain($otherOrganizationUser->id);
});

it('returns no users from the user resource when no user is authenticated', function () {
    expect(UserResource::getEloquentQuery()->count())->toBe(0);
});

it('keeps direct user viewing aligned with the shared-organization list scope', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $currentUser = User::factory()->create();
    $sameOrganizationUser = User::factory()->create();
    $otherOrganizationUser = User::factory()->create();

    Membership::factory()->create(['user_id' => $currentUser->id, 'organization_id' => $organizationA->id]);
    Membership::factory()->create(['user_id' => $sameOrganizationUser->id, 'organization_id' => $organizationA->id]);
    Membership::factory()->create(['user_id' => $otherOrganizationUser->id, 'organization_id' => $organizationB->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($currentUser)->allows('view', $sameOrganizationUser))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($currentUser)->allows('view', $otherOrganizationUser))->toBeFalse();
});
