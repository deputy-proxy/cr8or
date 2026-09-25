<?php

namespace App\Mcp\Tools;

use App\Models\AgentDescriptor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-agents')]
#[Description('Discover authorized agent resources in CR8OR.')]
class ListAgentDescriptorTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return AgentDescriptor::class;
    }

    protected static function filters(): array
    {
        return [
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'slug' => 'slug',
            'enabled' => 'enabled',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return ['slug'];
    }

    protected static function authorizeList(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', static::modelClass());
    }

    /**
     * @param  Builder<Model>  $query
     */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query;
    }
}
