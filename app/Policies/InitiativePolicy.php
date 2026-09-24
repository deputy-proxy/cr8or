<?php

namespace App\Policies;

use App\Models\Initiative;
use App\Models\Plan;
use App\Models\User;

class InitiativePolicy
{
    public function view(User $user, Initiative $initiative): bool
    {
        return (new EnterprisePolicy)->view($user, $initiative->plan->strategy->objective->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForPlan(User $user, Plan $plan): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $plan->strategy->objective->enterprise->organization);
    }

    public function update(User $user, Initiative $initiative): bool
    {
        return (new EnterprisePolicy)->update($user, $initiative->plan->strategy->objective->enterprise);
    }

    public function delete(User $user, Initiative $initiative): bool
    {
        return (new EnterprisePolicy)->delete($user, $initiative->plan->strategy->objective->enterprise);
    }
}