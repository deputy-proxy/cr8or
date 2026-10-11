<?php

namespace App\Policies;

use App\Models\GenerationRequest;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class GenerationRequestPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, GenerationRequest $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, GenerationRequest $record): bool
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

    public function view(User $u, GenerationRequest $r): bool
    {
        return (new EnterprisePolicy)->view($u, $r->enterprise);
    }
}
