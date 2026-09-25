<?php

namespace App\Mcp\Tools;

use App\Models\Audience;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-audiences')]
#[Description('Discover authorized audience resources in CR8OR.')]
class ListAudienceTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Audience::class;
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
            'enterprise_id' => 'enterprise_id',
            'name' => 'name',
            'description' => 'description',
            'status' => 'status',
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