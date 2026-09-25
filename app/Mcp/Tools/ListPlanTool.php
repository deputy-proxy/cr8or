<?php

namespace App\Mcp\Tools;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-plans')]
#[Description('Discover authorized plans in the strategy hierarchy.')]
class ListPlanTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Plan::class;
    }

    protected static function filters(): array
    {
        return [];
    }

    protected static function fields(): array
    {
        return ['id' => 'id', 'strategy_id' => 'strategy_id', 'name' => 'name', 'description' => 'description', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
    }

    protected static function searchableColumns(): array
    {
        return ['name'];
    }

    /** @param Builder<Model> $query */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->whereHas('strategy.objective.enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}
