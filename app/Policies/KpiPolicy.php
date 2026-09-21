<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Kpi;
use App\Models\User;

class KpiPolicy
{
    public function view(User $user, Kpi $kpi): bool
    {
        return $this->enterprisePolicy()->view($user, $kpi->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, Kpi $kpi): bool
    {
        return $this->enterprisePolicy()->update($user, $kpi->enterprise);
    }

    public function delete(User $user, Kpi $kpi): bool
    {
        return $this->enterprisePolicy()->delete($user, $kpi->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}