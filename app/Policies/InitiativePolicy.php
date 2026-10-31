<?php

namespace App\Policies;

use App\Models\Initiative;
use App\Models\Strategy;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class InitiativePolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Initiative $initiative): bool
    {
        return (new EnterprisePolicy)->view($user, $initiative->strategy->objective->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForStrategy(User $user, Strategy $strategy): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $strategy->objective->enterprise->organization);
    }

    public function update(User $user, Initiative $initiative): bool
    {
        return (new EnterprisePolicy)->update($user, $initiative->strategy->objective->enterprise);
    }

    public function delete(User $user, Initiative $initiative): bool
    {
        return (new EnterprisePolicy)->delete($user, $initiative->strategy->objective->enterprise);
    }
}
