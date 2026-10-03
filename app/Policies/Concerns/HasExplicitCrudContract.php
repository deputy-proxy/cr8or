<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait HasExplicitCrudContract
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, object $record): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
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
