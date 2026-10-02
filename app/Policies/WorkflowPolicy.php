<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;

class WorkflowPolicy
{
    public function view(User $user, Workflow $record): bool
    {
        return $this->organizationRole($user, $record) !== null;
    }

    public function create(User $user): bool
    {
        return $user->memberships()
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists();
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $user->memberships()
            ->where('organization_id', $enterprise->organization_id)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists();
    }

    public function update(User $user, Workflow $record): bool
    {
        $role = $this->organizationRole($user, $record);

        return in_array($role?->value, [MembershipRole::Owner->value, MembershipRole::Admin->value], true);
    }

    private function organizationRole(User $user, Workflow $record): ?MembershipRole
    {
        $role = $user->memberships()
            ->where('organization_id', $record->enterprise->organization_id)
            ->value('role');

        return $role === null ? null : MembershipRole::tryFrom($role);
    }
}
