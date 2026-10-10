<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class EventPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Event $event): bool
    {
        return (new EnterprisePolicy)->view($user, $event->enterprise);
    }
}
