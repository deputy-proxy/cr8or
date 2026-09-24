<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\AgentAssignment;
use App\Models\User;

class AgentAssignmentPolicy
{
    public function view(User $user, AgentAssignment $assignment): bool
    {
        return $this->hasRole($user, $assignment, MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForAgentAssignment(User $user, AgentAssignment $assignment): bool
    {
        return $this->hasRole($user, $assignment, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function update(User $user, AgentAssignment $assignment): bool
    {
        return $this->hasRole($user, $assignment, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function delete(User $user, AgentAssignment $assignment): bool
    {
        return $this->hasRole($user, $assignment, MembershipRole::Owner);
    }

    private function hasRole(User $user, AgentAssignment $assignment, MembershipRole ...$roles): bool
    {
        if ($assignment->enterprise_id !== null) {
            $enterprise = $assignment->enterprise;

            if ($enterprise === null || $enterprise->organization_id !== $assignment->organization_id) {
                return false;
            }
        }

        $role = $user->memberships()
            ->where('organization_id', $assignment->organization_id)
            ->value('role');

        return $role !== null && in_array($role, $roles, true);
    }
}
