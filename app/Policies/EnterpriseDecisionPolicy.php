<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use App\Models\User;

class EnterpriseDecisionPolicy
{
    public function view(User $user, EnterpriseDecision $decision): bool
    {
        return $this->enterprisePolicy()->view($user, $decision->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, EnterpriseDecision $decision): bool
    {
        return $this->enterprisePolicy()->update($user, $decision->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}