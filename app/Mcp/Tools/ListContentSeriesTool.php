<?php

namespace App\Mcp\Tools;

use App\Models\ContentSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-content-series')]
#[Description('Discover authorized content series resources in CR8OR.')]
class ListContentSeriesTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return ContentSeries::class;
    }

    protected static function filters(): array
    {
        return [
            'campaign_id' => 'Campaign id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'campaign_id' => 'campaign_id',
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

        return $query->whereHas('campaign.enterprise', fn (Builder $q) => $q->whereIn('organization_id', $organizationIds));
    }
}
