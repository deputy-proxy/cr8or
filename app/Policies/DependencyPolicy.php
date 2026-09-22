<?php

namespace App\Policies;

use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\User;

class DependencyPolicy
{
    public function view(User $user, Dependency $dependency): bool
    {
        return (new EnterprisePolicy)->view($user, $dependency->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->create($user, $enterprise->organization);
    }

    public function update(User $user, Dependency $dependency): bool
    {
        return (new EnterprisePolicy)->update($user, $dependency->enterprise);
    }

    public function delete(User $user, Dependency $dependency): bool
    {
        return (new EnterprisePolicy)->delete($user, $dependency->enterprise);
    }
}
