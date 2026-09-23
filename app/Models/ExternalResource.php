<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['integration_connection_id', 'organization_id', 'enterprise_id', 'content_item_id', 'asset_id', 'agent_execution_id', 'provider', 'resource_type', 'external_id', 'external_url', 'correlation_id', 'metadata'])]
class ExternalResource extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (ExternalResource $resource): void {
            $connection = IntegrationConnection::query()->find($resource->integration_connection_id);

            if ($connection === null
                || (int) $connection->organization_id !== (int) $resource->organization_id
                || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $resource->enterprise_id)
            ) {
                throw new LogicException('External resource connection must belong to its organization and enterprise.');
            }

            foreach ([
                'content_item_id' => ContentItem::class,
                'asset_id' => Asset::class,
            ] as $field => $model) {
                if ($resource->{$field} === null) {
                    continue;
                }

                $record = $model::query()->find($resource->{$field});
                if ($record === null || (int) $record->enterprise_id !== (int) $resource->enterprise_id) {
                    throw new LogicException("External resource {$field} must belong to its enterprise.");
                }
            }

            if ($resource->agent_execution_id !== null) {
                $execution = AgentExecution::query()->find($resource->agent_execution_id);
                if ($execution === null
                    || (int) $execution->organization_id !== (int) $resource->organization_id
                    || (int) $execution->enterprise_id !== (int) $resource->enterprise_id
                ) {
                    throw new LogicException('External resource Agent execution must belong to its organization and enterprise.');
                }
            }
        });
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }
}
