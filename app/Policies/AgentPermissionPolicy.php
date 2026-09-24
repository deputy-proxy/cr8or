<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use App\Models\User;

class AgentPermissionPolicy
{
    public function view(User $user, AgentPermission $permission): bool
    {
        return $this->role($user, $permission->agentAssignment) !== null;
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForAgentAssignment(User $user, AgentAssignment $assignment): bool
    {
        return $this->role($user, $assignment, MembershipRole::Owner, MembershipRole::Admin) !== null;
    }

    public function update(User $user, AgentPermission $permission): bool
    {
        return $this->role($user, $permission->agentAssignment, MembershipRole::Owner, MembershipRole::Admin) !== null;
    }

    public function updateForAgentAssignment(User $user, AgentPermission $permission, AgentAssignment $assignment): bool
    {
        return $this->role($user, $assignment, MembershipRole::Owner, MembershipRole::Admin) !== null;
    }

    public function delete(User $user, AgentPermission $permission): bool
    {
        return $this->role($user, $permission->agentAssignment, MembershipRole::Owner) !== null;
    }

    private function role(User $user, AgentAssignment $assignment, MembershipRole ...$roles): ?MembershipRole
    {
        if ($assignment->enterprise_id !== null && ($assignment->enterprise === null || $assignment->enterprise->organization_id !== $assignment->organization_id)) {
            return null;
        }
        $role = $user->memberships()->where('organization_id', $assignment->organization_id)->value('role');

        return $role !== null && ($roles === [] || in_array($role, $roles, true)) ? $role : null;
    }
}