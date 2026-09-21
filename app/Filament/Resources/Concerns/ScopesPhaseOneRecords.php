<?php

namespace App\Filament\Resources\Concerns;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesPhaseOneRecords
{
    protected static function currentUser(): ?User
    {
        $u = auth()->user();

        return $u instanceof User ? $u : null;
    }

    /** @return Builder<Membership> */
    protected static function authorizedOrganizationIds(): Builder
    {
        return Membership::query()->select('organization_id')->where('user_id', static::currentUser()?->getKey() ?? 0);
    }

    /** @return Builder<Membership> */
    protected static function manageableOrganizationIds(): Builder
    {
        return Membership::query()->select('organization_id')->where('user_id', static::currentUser()?->getKey() ?? 0)->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value]);
    }

    /** @return Builder<Enterprise> */
    protected static function manageableEnterpriseIds(): Builder
    {
        return Enterprise::query()->select('enterprises.id')->whereIn('organization_id', static::manageableOrganizationIds());
    }

    protected static function canManageAnyOrganization(): bool
    {
        return static::manageableOrganizationIds()->exists();
    }

    protected static function canManageAnyEnterprise(): bool
    {
        return static::manageableEnterpriseIds()->exists();
    }
}
