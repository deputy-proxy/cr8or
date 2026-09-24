<?php

namespace App\Policies;

use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\User;

class BusinessHealthResultPolicy
{
    public function view(User $user, BusinessHealthResult $result): bool
    {
        return $this->enterprisePolicy()->view($user, $result->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}