<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Policies\Concerns\HasExplicitCrudContract;

class WorkflowPolicy
{
    use HasExplicitCrudContract;

    public function view(User $user, Workflow $record): bool
    {
        if (! $record->isEnterpriseSpecific()) {
            return $user->memberships()->exists();
        }

        return $this->organizationRole($user, $record) !== null;
    }

    public function viewForEnterprise(User $user, Workflow $record, Enterprise $enterprise): bool
    {
        return $record->isAvailableForEnterprise($enterprise)
            && $user->memberships()
                ->where('organization_id', $enterprise->organization_id)
                ->exists();
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
        if (! $record->isEnterpriseSpecific()) {
            return $user->memberships()
                ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
                ->exists();
        }

        $role = $this->organizationRole($user, $record);

        return in_array($role?->value, [MembershipRole::Owner->value, MembershipRole::Admin->value], true);
    }

    public function delete(User $user, Workflow $record): bool
    {
        if (! $record->isEnterpriseSpecific()) {
            return $user->memberships()
                ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
                ->exists();
        }

        $role = $this->organizationRole($user, $record);

        return in_array($role?->value, [MembershipRole::Owner->value, MembershipRole::Admin->value], true);
    }

    private function organizationRole(User $user, Workflow $record): ?MembershipRole
    {
        $enterprise = $record->enterprise;

        if ($enterprise === null) {
            return null;
        }

        return $user->memberships()
            ->where('organization_id', $enterprise->organization_id)
            ->first()?->role;
    }
}