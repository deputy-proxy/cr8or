<?php

namespace App\Policies;

use App\Models\CommandWebhookDelivery;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class CommandWebhookDeliveryPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CommandWebhookDelivery $record): bool
    {
        return false;
    }

    public function delete(User $user, CommandWebhookDelivery $record): bool
    {
        return false;
    }
}
