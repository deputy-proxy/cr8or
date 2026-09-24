<?php

namespace App\Policies;

use App\Models\Objective;
use App\Models\Strategy;
use App\Models\User;

class StrategyPolicy
{
    public function view(User $user, Strategy $strategy): bool
    {
        return (new EnterprisePolicy)->view($user, $strategy->objective->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForObjective(User $user, Objective $objective): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $objective->enterprise->organization);
    }

    public function update(User $user, Strategy $strategy): bool
    {
        return (new EnterprisePolicy)->update($user, $strategy->objective->enterprise);
    }

    public function delete(User $user, Strategy $strategy): bool
    {
        return (new EnterprisePolicy)->delete($user, $strategy->objective->enterprise);
    }
}