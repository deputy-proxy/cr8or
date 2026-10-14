<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowContinuationOutbox;

final class WorkflowContinuationOutboxPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, WorkflowContinuationOutbox $outbox): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WorkflowContinuationOutbox $outbox): bool
    {
        return false;
    }

    public function delete(User $user, WorkflowContinuationOutbox $outbox): bool
    {
        return false;
    }
}
