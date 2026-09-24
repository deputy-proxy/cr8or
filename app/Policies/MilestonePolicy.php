<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Milestone;
use App\Models\User;

class MilestonePolicy
{
    public function view(User $user, Milestone $milestone): bool
    {
        return (new EnterprisePolicy)->view($user, $milestone->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return (new EnterprisePolicy)->update($user, $milestone->enterprise);
    }

    public function delete(User $user, Milestone $milestone): bool
    {
        return (new EnterprisePolicy)->delete($user, $milestone->enterprise);
    }
}
