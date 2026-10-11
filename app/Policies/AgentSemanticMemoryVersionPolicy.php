<?php

namespace App\Policies;

use App\Models\AgentSemanticMemoryVersion;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class AgentSemanticMemoryVersionPolicy
{
    use HasExplicitCrudContract;

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AgentSemanticMemoryVersion $record): bool
    {
        return false;
    }

    public function delete(User $user, AgentSemanticMemoryVersion $record): bool
    {
        return false;
    }
}
