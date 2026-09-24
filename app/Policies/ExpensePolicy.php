<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function view(User $user, Expense $expense): bool
    {
        return $this->enterprisePolicy()->view($user, $expense->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->enterprisePolicy()->update($user, $expense->enterprise);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->enterprisePolicy()->delete($user, $expense->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}
