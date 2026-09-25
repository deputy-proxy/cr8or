<?php

namespace App\Mcp\Tools;

use App\Models\Strategy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-strategies')]
#[Description('Discover authorized strategy resources in CR8OR.')]
class ListStrategyTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Strategy::class;
    }

    protected static function filters(): array
    {
        return [
            'objective_id' => 'Objective id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'objective_id' => 'objective_id',
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

        return $query->whereHas('objective.enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}

