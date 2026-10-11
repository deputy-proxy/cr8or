<?php

namespace App\Policies;

use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\User;

class CompetitorPolicy
{
    public function viewAny(User $user): bool
    {
        return app(StrategicRecordPolicy::class)->viewAny($user);
    }

    public function view(User $user, Competitor $record): bool
    {
        return app(StrategicRecordPolicy::class)->view($user, $record);
    }

    public function create(User $user): bool
    {
        return app(StrategicRecordPolicy::class)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    /**
     * These records are versioned; edits must create a successor record.
     */
    public function update(User $user, Competitor $record): bool
    {
        return false;
    }

    public function delete(User $user, Competitor $record): bool
    {
        return app(StrategicRecordPolicy::class)->delete($user, $record);
    }
}
