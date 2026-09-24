<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\FinancialPeriod;
use App\Models\User;

class FinancialPeriodPolicy
{
    public function view(User $user, FinancialPeriod $period): bool
    {
        return $this->enterprisePolicy()->view($user, $period->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, FinancialPeriod $period): bool
    {
        return $this->enterprisePolicy()->update($user, $period->enterprise);
    }

    public function delete(User $user, FinancialPeriod $period): bool
    {
        return $this->enterprisePolicy()->delete($user, $period->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}