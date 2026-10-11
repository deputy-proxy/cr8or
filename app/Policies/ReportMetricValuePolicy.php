<?php

namespace App\Policies;

use App\Models\ReportMetricValue;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class ReportMetricValuePolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReportMetricValue $record): bool
    {
        return false;
    }

    public function delete(User $user, ReportMetricValue $record): bool
    {
        return false;
    }
}
