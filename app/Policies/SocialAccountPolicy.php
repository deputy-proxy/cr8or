<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    public function view(User $u, SocialAccount $a): bool
    {
        return (new EnterprisePolicy)->view($u, $a->enterprise);
    }

    public function create(User $u, Enterprise $e): bool
    {
        return (new EnterprisePolicy)->update($u, $e);
    }

    public function update(User $u, SocialAccount $a): bool
    {
        return (new EnterprisePolicy)->update($u, $a->enterprise);
    }

    public function delete(User $u, SocialAccount $a): bool
    {
        return (new EnterprisePolicy)->delete($u, $a->enterprise);
    }
}
