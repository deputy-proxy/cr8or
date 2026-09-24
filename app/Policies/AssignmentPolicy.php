<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\Enterprise;
use App\Models\User;

class AssignmentPolicy
{
    public function view(User $user, Assignment $assignment): bool
    {
        return (new EnterprisePolicy)->view($user, $assignment->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return (new EnterprisePolicy)->update($user, $assignment->enterprise);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return (new EnterprisePolicy)->delete($user, $assignment->enterprise);
    }
}
