<?php

namespace App\Filament\Resources\Concerns;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Builder;

trait ScopesPhaseSixRecords
{
    use ScopesPhaseOneRecords;

    /** @return Builder<Enterprise> */
    protected static function authorizedEnterpriseIds(): Builder
    {
        return Enterprise::query()
            ->select('enterprises.id')
            ->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    protected static function canManageAnyEnterprise(): bool
    {
        return static::manageableEnterpriseIds()->exists();
    }
}