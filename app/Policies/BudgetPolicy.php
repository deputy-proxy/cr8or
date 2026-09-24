<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\Enterprise;
use App\Models\User;

class BudgetPolicy
{
    public function view(User $user, Budget $budget): bool
    {
        return $this->enterprisePolicy()->view($user, $budget->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Budget $budget): bool
    {
        return $this->enterprisePolicy()->update($user, $budget->enterprise);
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $this->enterprisePolicy()->delete($user, $budget->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}