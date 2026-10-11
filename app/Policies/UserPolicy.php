<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class UserPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $record): bool
    {
        if ((int) $user->getKey() === (int) $record->getKey()) {
            return true;
        }

        return $record->memberships()
            ->whereIn('organization_id', $user->memberships()->select('organization_id'))
            ->exists();
    }
}
