<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\WorkItem;

class WorkItemPolicy
{
    public function view(User $user, WorkItem $item): bool
    {
        return (new EnterprisePolicy)->view($user, $item->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, WorkItem $item): bool
    {
        return (new EnterprisePolicy)->update($user, $item->enterprise);
    }

    public function delete(User $user, WorkItem $item): bool
    {
        return (new EnterprisePolicy)->delete($user, $item->enterprise);
    }
}