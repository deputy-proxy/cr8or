<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\AgentDecision;
use App\Models\User;

class AgentDecisionPolicy
{
    public function view(User $user, AgentDecision $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    public function create(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function createForAgentDecision(User $user, AgentDecision $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    public function update(User $user, AgentDecision $record): bool
    {
        return false;
    }

    public function delete(User $user, AgentDecision $record): bool
    {
        return false;
    }

    private function organizationRole(User $user, AgentDecision $record): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $record->organization_id)
            ->value('role');
    }
}