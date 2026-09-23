<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\FinancialReport;
use App\Models\User;

class FinancialReportPolicy
{
    public function view(User $user, FinancialReport $report): bool
    {
        return $this->enterprisePolicy()->view($user, $report->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}