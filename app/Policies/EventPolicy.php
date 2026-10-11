<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class EventPolicy
{
    use HasExplicitCrudContract;

    public function update(User $user, Event $event): bool
    {
        return false;
    }

    public function delete(User $user, Event $event): bool
    {
        return false;
    }

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function view(User $user, Event $event): bool
    {
        return (new EnterprisePolicy)->view($user, $event->enterprise);
    }
}
