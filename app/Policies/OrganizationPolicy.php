<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member);
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, MembershipRole::Owner);
    }

    private function hasRole(User $user, Organization $organization, MembershipRole ...$roles): bool
    {
        $membership = $user->memberships()
            ->where('organization_id', $organization->getKey())
            ->first();

        return $membership !== null && in_array($membership->role, $roles, true);
    }
}
