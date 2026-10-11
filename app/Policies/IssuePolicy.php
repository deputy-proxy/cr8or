<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class IssuePolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, Issue $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, Issue $record): bool
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

    public function view(User $user, Issue $issue): bool
    {
        return (new EnterprisePolicy)->view($user, $issue->enterprise);
    }
}
