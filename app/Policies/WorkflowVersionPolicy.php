<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\Policies\Concerns\HasExplicitCrudContract;

class WorkflowVersionPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, WorkflowVersion $record): bool
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

    public function update(User $user, WorkflowVersion $record): bool
    {
        $role = $this->organizationRole($user, $record);

        return in_array($role?->value, [MembershipRole::Owner->value, MembershipRole::Admin->value], true);
    }

    public function delete(User $user, WorkflowVersion $record): bool
    {
        $role = $this->organizationRole($user, $record);

        return in_array($role?->value, [MembershipRole::Owner->value, MembershipRole::Admin->value], true);
    }

    private function organizationRole(User $user, WorkflowVersion $record): ?MembershipRole
    {
        return $user->memberships()
            ->where('organization_id', $record->enterprise->organization_id)
            ->first()?->role;
    }
}
