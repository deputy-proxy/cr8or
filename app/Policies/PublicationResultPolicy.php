<?php

namespace App\Policies;

use App\Models\PublicationResult;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class PublicationResultPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, PublicationResult $record): bool
    {
        return (new EnterprisePolicy)->view($user, $record->enterprise()->firstOrFail());
    }

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