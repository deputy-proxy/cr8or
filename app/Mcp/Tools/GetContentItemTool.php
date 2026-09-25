<?php

namespace App\Mcp\Tools;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-content-item')]
#[Description('Get an authorized content item by id from CR8OR.')]
class GetContentItemTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return ContentItem::class;
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