<?php

namespace App\Mcp\Tools;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-content-items')]
#[Description('Discover authorized content item resources in CR8OR.')]
class ListContentItemTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return ContentItem::class;
    }

    protected static function filters(): array
    {
        return [
            'campaign_id' => 'Campaign id.',
            'content_series_id' => 'Content series id.',
            'channel_id' => 'Channel id.',
            'audience_id' => 'Audience id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'enterprise_id' => 'enterprise_id',
            'campaign_id' => 'campaign_id',
            'content_series_id' => 'content_series_id',
            'channel_id' => 'channel_id',
            'audience_id' => 'audience_id',
            'title' => 'title',
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return ['title'];
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
