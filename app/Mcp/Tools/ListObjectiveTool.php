<?php

namespace App\Mcp\Tools;

use App\Models\Objective;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-objectives')]
#[Description('Discover authorized objective resources in CR8OR.')]
class ListObjectiveTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Objective::class;
    }

    protected static function filters(): array
    {
        return [
            'goal_id' => 'Goal id.',
            'kpi_id' => 'KPI id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'enterprise_id' => 'enterprise_id',
            'goal_id' => 'goal_id',
            'kpi_id' => 'kpi_id',
            'name' => 'name',
            'description' => 'description',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return ['name'];
    }

    /**
     * @param  Builder<Model>  $query
     */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}