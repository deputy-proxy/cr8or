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
        $workflow = $record->workflow;

        if ($workflow !== null && ! $workflow->isEnterpriseSpecific()) {
            return $user->memberships()->exists();
        }

        return $this->organizationRole($user, $record) !== null;
    }

    /**
     * This record is created through its domain operation, not generic Filament CRUD.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $user->memberships()
            ->where('organization_id', $enterprise->organization_id)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists();
    }

    /**
     * Published workflow versions are managed through workflow lifecycle operations.
     */
    public function update(User $user, WorkflowVersion $record): bool
    {
        return false;
    }

    /**
     * Published workflow versions are managed through workflow lifecycle operations.
     */
    public function delete(User $user, WorkflowVersion $record): bool
    {
        return false;
    }

    private function organizationRole(User $user, WorkflowVersion $record): ?MembershipRole
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