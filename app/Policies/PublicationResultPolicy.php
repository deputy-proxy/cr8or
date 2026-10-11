<?php

namespace App\Policies;

use App\Models\PublicationResult;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class PublicationResultPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PublicationResult $record): bool
    {
        return false;
    }

    public function delete(User $user, PublicationResult $record): bool
    {
        return false;
    }
}
