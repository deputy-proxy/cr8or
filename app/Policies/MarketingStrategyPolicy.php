<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\User;

class MarketingStrategyPolicy
{
    public function view(User $user, MarketingStrategy $strategy): bool
    {
        return (new EnterprisePolicy)->view($user, $strategy->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->create($user, $enterprise->organization);
    }

    public function update(User $user, MarketingStrategy $strategy): bool
    {
        return (new EnterprisePolicy)->update($user, $strategy->enterprise);
    }

    public function delete(User $user, MarketingStrategy $strategy): bool
    {
        return (new EnterprisePolicy)->delete($user, $strategy->enterprise);
    }
}