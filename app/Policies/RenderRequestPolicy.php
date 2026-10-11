<?php

namespace App\Policies;

use App\Models\RenderRequest;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class RenderRequestPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, RenderRequest $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, RenderRequest $record): bool
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

    public function view(User $u, RenderRequest $r): bool
    {
        return (new EnterprisePolicy)->view($u, $r->enterprise);
    }
}
