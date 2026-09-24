<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Revenue;
use App\Models\User;

class RevenuePolicy
{
    public function view(User $user, Revenue $revenue): bool
    {
        return $this->enterprisePolicy()->view($user, $revenue->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Revenue $revenue): bool
    {
        return $this->enterprisePolicy()->update($user, $revenue->enterprise);
    }

    public function delete(User $user, Revenue $revenue): bool
    {
        return $this->enterprisePolicy()->delete($user, $revenue->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}
