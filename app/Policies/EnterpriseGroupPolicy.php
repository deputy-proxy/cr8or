<?php

namespace App\Policies;

use App\Models\EnterpriseGroup;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class EnterpriseGroupPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, EnterpriseGroup $record): bool
    {
        return (new OrganizationPolicy)->view($user, $record->organization);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForOrganization(User $user, Organization $organization): bool
    {
        return (new OrganizationPolicy)->update($user, $organization);
    }

    public function update(User $user, EnterpriseGroup $record): bool
    {
        return (new OrganizationPolicy)->update($user, $record->organization);
    }

    public function delete(User $user, EnterpriseGroup $record): bool
    {
        return (new OrganizationPolicy)->delete($user, $record->organization);
    }
}
