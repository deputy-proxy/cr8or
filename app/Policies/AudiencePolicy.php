<?php

namespace App\Policies;

use App\Models\Audience;
use App\Models\Enterprise;
use App\Models\User;

class AudiencePolicy
{
    public function view(User $user, Audience $audience): bool
    {
        return (new EnterprisePolicy)->view($user, $audience->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->create($user, $enterprise->organization);
    }

    public function update(User $user, Audience $audience): bool
    {
        return (new EnterprisePolicy)->update($user, $audience->enterprise);
    }

    public function delete(User $user, Audience $audience): bool
    {
        return (new EnterprisePolicy)->delete($user, $audience->enterprise);
    }
}