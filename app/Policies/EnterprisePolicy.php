<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\User;

class EnterprisePolicy
{
    public function view(User $user, Enterprise $enterprise): bool
    {
        return $this->hasRole($user, $enterprise->organization_id, MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization->getKey(), MembershipRole::Owner, MembershipRole::Admin);
    }

    public function update(User $user, Enterprise $enterprise): bool
    {
        return $this->hasRole($user, $enterprise->organization_id, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function delete(User $user, Enterprise $enterprise): bool
    {
        return $this->hasRole($user, $enterprise->organization_id, MembershipRole::Owner);
    }

    private function hasRole(User $user, int $organizationId, MembershipRole ...$roles): bool
    {
        $role = $user->memberships()
            ->where('organization_id', $organizationId)
            ->value('role');

        return $role !== null && in_array($role, $roles, true);
    }
}