<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\FinancialReport;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class FinancialReportPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, FinancialReport $report): bool
    {
        return $this->enterprisePolicy()->view($user, $report->enterprise);
    }

    public function create(User $user): bool
    {
        return false;
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
