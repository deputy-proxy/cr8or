<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-publication')]
#[Description('Get publication status and the latest safe provider result for an authorized enterprise.')]
final class GetPublicationTool extends DiscoveryGetTool
{
    protected static function modelClass(): string
    {
        return Publication::class;
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

    /** @param Builder<Model> $query */
    // @phpstan-ignore missingType.generics
    protected static function scopeQuery(Builder $query, User $actor): Builder
    {
        return $query->whereIn('enterprise_id', Enterprise::query()
            ->whereIn('organization_id', $actor->memberships()->select('organization_id'))
            ->select('id'));
    }

    /** @return array<string, mixed> */
    protected static function serializeModel(Model $model): array
    {
        if (! $model instanceof Publication) {
            throw new \LogicException('Unexpected publication record.');
        }

        $result = parent::serializeModel($model);
        $latest = $model->results()->latest('id')->first();
        $result['latest_result'] = $latest instanceof PublicationResult ? [
            'id' => $latest->id,
            'provider' => $latest->provider,
            'network' => $latest->network,
            'provider_status' => $latest->provider_status,
            'external_id' => $latest->external_id,
            'external_url' => $latest->external_url,
            'failure_code' => $latest->failure_code,
            'failure_reason' => $latest->failure_reason,
            'recorded_at' => $latest->recorded_at,
        ] : null;

        return $result;
    }
}
