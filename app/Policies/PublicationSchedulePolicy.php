<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\PublicationSchedule;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class PublicationSchedulePolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, PublicationSchedule $schedule): bool
    {
        return (new EnterprisePolicy)->view($user, $schedule->enterprise);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->update($user, $enterprise);
    }

    public function update(User $user, object $record): bool
    {
        return false;
    }

    public function delete(User $user, object $record): bool
    {
        return false;
    }
}
