<?php

namespace App\Filament\Resources\Concerns;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesKnowledgeRecords
{
    public static function authorizedOrganizationIds(): Builder
    {
        $user = auth()->user();
        $userId = $user instanceof User ? $user->getKey() : 0;

        return Membership::query()->select('organization_id')->where('user_id', $userId);
    }

    public static function manageableEnterpriseIds(): Builder
    {
        return Enterprise::query()->select('id')->whereIn('organization_id', static::authorizedOrganizationIds()->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value]));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return auth()->check() && static::manageableEnterpriseIds()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('enterprise_id', Enterprise::query()->select('id')->whereIn('organization_id', static::authorizedOrganizationIds()));
    }
}
