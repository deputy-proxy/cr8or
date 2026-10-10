<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\MetricDefinition;
use App\Models\User;

class MetricDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, MetricDefinition $metricDefinition): bool
    {
        return $this->hasRole(
            $user,
            $metricDefinition->enterprise?->organization_id,
            MembershipRole::Owner,
            MembershipRole::Admin,
            MembershipRole::Member,
        );
    }

    public function create(User $user): bool
    {
        return Enterprise::query()
            ->whereIn('organization_id', $user->memberships()
                ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
                ->select('organization_id'))
            ->exists();
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return $this->hasRole(
            $user,
            $enterprise->organization_id,
            MembershipRole::Owner,
            MembershipRole::Admin,
        );
    }

    public function update(User $user, MetricDefinition $metricDefinition): bool
    {
        return $this->hasRole(
            $user,
            $metricDefinition->enterprise?->organization_id,
            MembershipRole::Owner,
            MembershipRole::Admin,
        );
    }

    public function delete(User $user, MetricDefinition $metricDefinition): bool
    {
        return false;
    }

    private function hasRole(User $user, ?int $organizationId, MembershipRole ...$roles): bool
    {
        if ($organizationId === null) {
            return false;
        }

        $role = $user->memberships()
            ->where('organization_id', $organizationId)
            ->value('role');

        if ($role === null) {
            return false;
        }

        $role = $role instanceof MembershipRole
            ? $role
            : MembershipRole::tryFrom((string) $role);

        return $role !== null && in_array($role, $roles, true);
    }
}
