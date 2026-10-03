<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowExecution;

class WorkflowExecutionPolicy
{
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
        return $user->memberships()
            ->where('organization_id', $execution->organization_id)
            ->exists();
    }
}
