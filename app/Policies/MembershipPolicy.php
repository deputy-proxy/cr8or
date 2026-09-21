<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

class MembershipPolicy
{
    public function view(User $user, Membership $membership): bool
    {
        return $this->hasRole(
            $user,
            $membership->organization_id,
            MembershipRole::Owner,
            MembershipRole::Admin,
            MembershipRole::Member,
        );
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasRole(
            $user,
            $organization->getKey(),
            MembershipRole::Owner,
            MembershipRole::Admin,
        );
    }

    public function update(User $user, Membership $membership): bool
    {
        $actorRole = $this->membershipRole($user, $membership->organization_id);

        if ($actorRole === null) {
            return false;
        }

        if ($actorRole === MembershipRole::Owner) {
            return true;
        }

        return $actorRole === MembershipRole::Admin
            && $membership->role === MembershipRole::Member;
    }

    public function delete(User $user, Membership $membership): bool
    {
        $actorRole = $this->membershipRole($user, $membership->organization_id);

        if ($actorRole === null) {
            return false;
        }

        if ($actorRole === MembershipRole::Owner) {
            return true;
        }

        return $actorRole === MembershipRole::Admin
            && $membership->role === MembershipRole::Member;
    }

    private function hasRole(User $user, int $organizationId, MembershipRole ...$roles): bool
    {
        $role = $this->membershipRole($user, $organizationId);

        return $role !== null && in_array($role, $roles, true);
    }

    private function membershipRole(User $user, int $organizationId): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $organizationId)
            ->value('role');
    }
}
