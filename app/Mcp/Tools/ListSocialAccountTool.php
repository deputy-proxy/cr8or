<?php

namespace App\Mcp\Tools;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-social-accounts')]
#[Description('Discover connected social account context for authorized enterprises without exposing credentials or tokens.')]
class ListSocialAccountTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return SocialAccount::class;
    }

    protected static function filters(): array
    {
        return ['channel_id' => 'Channel id.'];
    }

    protected static function fields(): array
    {
        return ['id' => 'id', 'enterprise_id' => 'enterprise_id', 'channel_id' => 'channel_id', 'provider' => 'provider', 'name' => 'name', 'external_id' => 'external_id', 'status' => 'status', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
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
