<?php

namespace App\Policies;

use App\Models\AgentDelegation;
use App\Models\User;

class AgentDelegationPolicy
{
    public function view(User $user, AgentDelegation $delegation): bool
    {
        return $user->memberships()->where('organization_id', $delegation->organization_id)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function createForAgentDelegation(User $user, AgentDelegation $delegation): bool
    {
        return false;
    }

    public function update(User $user, AgentDelegation $delegation): bool
    {
        return false;
    }

    public function delete(User $user, AgentDelegation $delegation): bool
    {
        return false;
    }
}
