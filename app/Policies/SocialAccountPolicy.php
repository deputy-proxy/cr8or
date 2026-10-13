<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\SocialAccount;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class SocialAccountPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, SocialAccount $account): bool
    {
        return (new EnterprisePolicy)->view($user, $account->enterprise);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return false;
    }

    public function update(User $user, SocialAccount $account): bool
    {
        return (new EnterprisePolicy)->update($user, $account->enterprise);
    }

    public function disable(User $user, SocialAccount $account): bool
    {
        return (new EnterprisePolicy)->delete($user, $account->enterprise);
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        return false;
    }
}