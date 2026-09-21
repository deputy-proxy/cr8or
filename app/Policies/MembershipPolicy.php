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
        return $this->belongsToOrganization($user, $membership)
            && $membership->organization_id === $this->organizationMembership($user, $membership)->organization_id;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function update(User $user, Membership $membership): bool
    {
        if (! $this->belongsToOrganization($user, $membership)) {
            return false;
        }

        $actorRole = $this->organizationMembership($user, $membership)->role;

        if ($actorRole === MembershipRole::Owner) {
            return true;
        }

        return $actorRole === MembershipRole::Admin
            && $membership->role === MembershipRole::Member;
    }

    public function delete(User $user, Membership $membership): bool
    {
        if (! $this->belongsToOrganization($user, $membership)) {
            return false;
        }

        $actorRole = $this->organizationMembership($user, $membership)->role;

        if ($actorRole === MembershipRole::Owner) {
            return true;
        }

        return $actorRole === MembershipRole::Admin
            && $membership->role === MembershipRole::Member;
    }

    private function belongsToOrganization(User $user, Membership $membership): bool
    {
        return $user->memberships()
            ->where('organization_id', $membership->organization_id)
            ->exists();
    }

    private function hasRole(User $user, Organization $organization, MembershipRole ...$roles): bool
    {
        $membership = $user->memberships()
            ->where('organization_id', $organization->getKey())
            ->first();

        return $membership !== null && in_array($membership->role, $roles, true);
    }

    private function organizationMembership(User $user, Membership $membership): Membership
    {
        return $user->memberships()
            ->where('organization_id', $membership->organization_id)
            ->firstOrFail();
    }
}
