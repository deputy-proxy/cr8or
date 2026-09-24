<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return (new EnterprisePolicy)->view($user, $project->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Project $project): bool
    {
        return (new EnterprisePolicy)->update($user, $project->enterprise);
    }

    public function delete(User $user, Project $project): bool
    {
        return (new EnterprisePolicy)->delete($user, $project->enterprise);
    }
}
