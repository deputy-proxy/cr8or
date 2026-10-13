<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\PublicationResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-publication-results')]
#[Description('List sanitized provider outcomes and reconciliation history for authorized enterprise publications.')]
final class ListPublicationResultsTool extends DiscoveryListTool
{
    protected static function modelClass(): string
    {
        return PublicationResult::class;
    }

    protected static function filters(): array
    {
        return ['publication_id' => 'Publication id.'];
    }

    protected static function fields(): array
    {
        return [
            'id' => 'id',
            'enterprise_id' => 'enterprise_id',
            'publication_id' => 'publication_id',
            'publishing_job_id' => 'publishing_job_id',
            'provider' => 'provider',
            'network' => 'network',
            'provider_status' => 'provider_status',
            'external_id' => 'external_id',
            'external_url' => 'external_url',
            'failure_code' => 'failure_code',
            'failure_reason' => 'failure_reason',
            'recorded_at' => 'recorded_at',
        ];
    }

    protected static function searchableColumns(): array
    {
        return [];
    }

    protected static function authorizeList(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', PublicationResult::class);
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
