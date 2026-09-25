<?php

namespace App\Mcp\Tools;

use App\Models\Kpi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-kpi')]
#[Description('Get an authorized enterprise KPI.')]
class GetKpiTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return Kpi::class;
    }

    protected static function fields(): array
    {
        return ['id' => 'id', 'enterprise_id' => 'enterprise_id', 'name' => 'name', 'definition' => 'definition', 'unit' => 'unit', 'target_value' => 'target_value', 'current_value' => 'current_value', 'status' => 'status', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
    }

    /** @param Builder<Model> $query */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}