<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowExecution;
use App\Policies\Concerns\HasExplicitCrudContract;
use Illuminate\Support\Facades\Gate;

class WorkflowExecutionPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function delete(User $user, WorkflowExecution $record): bool
    {
        return false;
    }

    /**
     * This record is changed only through its domain lifecycle operation.
     */
    public function update(User $user, WorkflowExecution $record): bool
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

    public function view(User $user, WorkflowExecution $execution): bool
    {
        return $this->canAccess($user, $execution);
    }

    public function resume(User $user, WorkflowExecution $execution): bool
    {
        return $this->canAccess($user, $execution);
    }

    private function canAccess(User $user, WorkflowExecution $execution): bool
    {
        $execution->loadMissing('enterprise');

        return $execution->enterprise !== null
            && Gate::forUser($user)->allows('view', $execution->enterprise);
    }
}
