<?php

namespace App\Policies;

use App\Models\ExternalResource;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class ExternalResourcePolicy
{
    use HasExplicitCrudContract;

    /**
     * External resources are persisted by the integration operation.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, ExternalResource $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, ExternalResource $record): bool
    {
        return false;
    }
}
