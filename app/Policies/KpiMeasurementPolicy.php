<?php

namespace App\Policies;

use App\Models\KpiMeasurement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class KpiMeasurementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, KpiMeasurement $measurement): bool
    {
        return $measurement->kpi !== null
            && Gate::forUser($user)->allows('view', $measurement->kpi);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, KpiMeasurement $measurement): bool
    {
        return false;
    }

    public function delete(User $user, KpiMeasurement $measurement): bool
    {
        return false;
    }
}
