<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-enterprises')]
#[Description('Discover authorized enterprise resources in CR8OR.')]
class ListEnterpriseTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Enterprise::class;
    }

    protected static function filters(): array
    {
        return [
        ];
    }

    protected static function fields(): array
    {
        return [
            'organization_id' => 'organization_id',
            'name' => 'name',
            'slug' => 'slug',
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return ['name', 'slug'];
    }

    /**
     * @param  Builder<Model>  $query
     */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        $organizationIds = $actor->memberships()->pluck('organization_id');

        return $query->whereIn('organization_id', $organizationIds);
    }
}