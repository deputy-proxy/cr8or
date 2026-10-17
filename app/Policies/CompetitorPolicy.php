<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\User;

class CompetitorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, Competitor $record): bool
    {
        return $this->enterpriseRole($user, $record->enterprise_id, MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member);
    }

    public function create(User $user): bool
    {
        return $user->memberships()
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists();
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    /** Revising means creating a successor version, never mutating this version's descriptive fields. */
    public function update(User $user, Competitor $record): bool
    {
        return $record->is_current
            && $record->status === Competitor::STATUS_ACTIVE
            && $this->enterpriseRole($user, $record->enterprise_id, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function archive(User $user, Competitor $record): bool
    {
        return $record->is_current
            && $record->status === Competitor::STATUS_ACTIVE
            && $this->enterpriseRole($user, $record->enterprise_id, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function delete(User $user, Competitor $record): bool
    {
        return false;
    }

    private function enterpriseRole(User $user, int $enterpriseId, MembershipRole ...$roles): bool
    {
        $enterprise = Enterprise::query()->find($enterpriseId);

        if ($enterprise === null) {
            return false;
        }

        $role = $user->memberships()
            ->where('organization_id', $enterprise->organization_id)
            ->value('role');

        $role = $role instanceof MembershipRole ? $role->value : (string) $role;

        return in_array($role, array_map(static fn (MembershipRole $role): string => $role->value, $roles), true);
    }
}
