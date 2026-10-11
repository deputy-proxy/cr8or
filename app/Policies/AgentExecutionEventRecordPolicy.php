<?php

namespace App\Policies;

use App\Models\AgentExecutionEventRecord;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class AgentExecutionEventRecordPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AgentExecutionEventRecord $record): bool
    {
        return false;
    }

    public function delete(User $user, AgentExecutionEventRecord $record): bool
    {
        return false;
    }
}
