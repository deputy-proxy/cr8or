<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\PublicationSchedule;
use App\Models\User;

class PublicationSchedulePolicy
{
    public function view(User $user, PublicationSchedule $schedule): bool
    {
        return (new EnterprisePolicy)->view($user, $schedule->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->update($user, $enterprise);
    }

    public function update(User $user, PublicationSchedule $schedule): bool
    {
        return (new EnterprisePolicy)->update($user, $schedule->enterprise);
    }

    public function delete(User $user, PublicationSchedule $schedule): bool
    {
        return (new EnterprisePolicy)->delete($user, $schedule->enterprise);
    }
}