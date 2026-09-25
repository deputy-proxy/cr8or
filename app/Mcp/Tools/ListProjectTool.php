<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-projects')]
#[Description('Discover authorized projects in CR8OR.')]
class ListProjectTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Project::class;
    }

    protected static function filters(): array
    {
        return ['strategy_id' => 'Strategy id.'];
    }

    protected static function fields(): array
    {
        return ['id' => 'id', 'enterprise_id' => 'enterprise_id', 'strategy_id' => 'strategy_id', 'plan_id' => 'plan_id', 'initiative_id' => 'initiative_id', 'name' => 'name', 'description' => 'description', 'status' => 'status', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
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

        return $query->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}
