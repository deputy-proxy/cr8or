<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\SocialIdentity;
use App\Models\User;

class SocialIdentityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, SocialIdentity $identity): bool
    {
        return (new OrganizationPolicy)->view($user, $identity->organization);
    }

    /**
     * Social identities are created only by the authorized network connection flow,
     * never through generic model CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function createForOrganization(User $user, Organization $organization): bool
    {
        return (new OrganizationPolicy)->update($user, $organization);
    }

    public function update(User $user, SocialIdentity $identity): bool
    {
        return (new OrganizationPolicy)->update($user, $identity->organization);
    }

    public function delete(User $user, SocialIdentity $identity): bool
    {
        return false;
    }
}
