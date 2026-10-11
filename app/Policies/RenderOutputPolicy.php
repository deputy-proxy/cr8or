<?php

namespace App\Policies;

use App\Models\RenderOutput;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class RenderOutputPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RenderOutput $record): bool
    {
        return false;
    }

    public function delete(User $user, RenderOutput $record): bool
    {
        return false;
    }
}
