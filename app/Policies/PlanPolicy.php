<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\Strategy;
use App\Models\User;

class PlanPolicy
{
    public function view(User $user, Plan $plan): bool
    {
        return (new EnterprisePolicy)->view($user, $plan->strategy->objective->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForStrategy(User $user, Strategy $strategy): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $strategy->objective->enterprise->organization);
    }

    public function update(User $user, Plan $plan): bool
    {
        return (new EnterprisePolicy)->update($user, $plan->strategy->objective->enterprise);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return (new EnterprisePolicy)->delete($user, $plan->strategy->objective->enterprise);
    }
}
