<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Mission;
use App\Models\User;
use App\Models\Vision;

class StrategicRecordPolicy
{
    public function view(User $user, Vision|Mission|Competitor $record): bool
    {
        return $this->enterpriseRole($user, $record->enterprise_id, MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member);
    }

    public function create(User $user): bool
    {
        return $this->hasAnyManageRole($user);
    }

    public function update(User $user, Vision|Mission|Competitor $record): bool
    {
        return $this->enterpriseRole($user, $record->enterprise_id, MembershipRole::Owner, MembershipRole::Admin);
    }

    public function delete(User $user, Vision|Mission|Competitor $record): bool
    {
        return false;
    }

    private function hasAnyManageRole(User $user): bool
    {
        return $user->memberships()
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists();
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

        return in_array($role, array_map(fn (MembershipRole $role): string => $role->value, $roles), true);
    }
}