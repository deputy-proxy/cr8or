<?php

namespace App\Policies;

use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\User;

class DecisionPolicy
{
    public function view(User $user, Decision $decision): bool
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

    public function update(User $user, Decision $decision): bool
    {
        return $this->enterprisePolicy()->update($user, $decision->enterprise);
    }

    public function delete(User $user, Decision $decision): bool
    {
        return false;
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}
