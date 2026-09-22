<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Objective;
use App\Models\User;

class ObjectivePolicy
{
    public function view(User $user, Objective $objective): bool
    {
        return (new EnterprisePolicy)->view($user, $objective->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->create($user, $enterprise->organization);
    }

    public function update(User $user, Objective $objective): bool
    {
        return (new EnterprisePolicy)->update($user, $objective->enterprise);
    }

    public function delete(User $user, Objective $objective): bool
    {
        return (new EnterprisePolicy)->delete($user, $objective->enterprise);
    }
}