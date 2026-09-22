<?php

namespace App\Filament\Resources\Concerns;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesKnowledgeRecords
{
    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** @return Builder<Membership> */
    public static function authorizedOrganizationIds(): Builder
    {
        return Membership::query()->select('organization_id')->where('user_id', static::currentUser()?->getKey() ?? 0);
    }

    /** @return Builder<Enterprise> */
    public static function manageableEnterpriseIds(): Builder
    {
        return Enterprise::query()->select('enterprises.id')
            ->whereIn('organization_id', static::authorizedOrganizationIds()->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value]));
    }

    protected static function canManageAnyEnterprise(): bool
    {
        return static::manageableEnterpriseIds()->exists();
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('enterprise_id', Enterprise::query()
            ->select('id')->whereIn('organization_id', static::authorizedOrganizationIds()));
    }
}
