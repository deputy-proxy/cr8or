<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-publications')]
#[Description('List publication status for publications in enterprises the current actor may access.')]
final class ListPublicationTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return Publication::class;
    }

    protected static function filters(): array
    {
        return [
            'content_item_id' => 'Content item id.',
            'social_account_id' => 'Social account id.',
        ];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'enterprise_id' => 'enterprise_id',
            'content_item_id' => 'content_item_id',
            'channel_id' => 'channel_id',
            'social_account_id' => 'social_account_id',
            'target_provider' => 'target_provider',
            'target_external_id' => 'target_external_id',
            'target_name' => 'target_name',
            'status' => 'status',
            'external_id' => 'external_id',
            'external_url' => 'external_url',
            'scheduled_at' => 'scheduled_at',
            'submitted_at' => 'submitted_at',
            'published_at' => 'published_at',
            'failure_code' => 'failure_code',
            'failure_reason' => 'failure_reason',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return [];
    }

    protected static function authorizeList(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', Publication::class);
    }

    /** @param Builder<Model> $query */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        return $query->whereIn('enterprise_id', Enterprise::query()
            ->whereIn('organization_id', $actor->memberships()->select('organization_id'))
            ->select('id'));
    }
}
