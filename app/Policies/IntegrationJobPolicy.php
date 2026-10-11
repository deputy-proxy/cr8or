<?php

namespace App\Policies;

use App\Models\IntegrationJob;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class IntegrationJobPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, IntegrationJob $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, IntegrationJob $record): bool
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
}
