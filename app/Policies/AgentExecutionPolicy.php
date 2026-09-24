<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\AgentExecution;
use App\Models\User;

class AgentExecutionPolicy
{
    public function view(User $user, AgentExecution $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    public function create(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function createForAgentExecution(User $user, AgentExecution $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    public function update(User $user, AgentExecution $record): bool
    {
        return false;
    }

    public function delete(User $user, AgentExecution $record): bool
    {
        return false;
    }

    private function organizationRole(User $user, AgentExecution $record): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $record->organization_id)
            ->value('role');
    }
}
