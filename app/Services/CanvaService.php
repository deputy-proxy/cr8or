<?php

namespace App\Services;

use App\Contracts\CanvaClient;
use App\Data\CanvaDesignRequest;
use App\Exceptions\CanvaClientException;
use App\Models\AgentExecution;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\ExternalResource;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class CanvaService
{
    public function __construct(private readonly CanvaClient $client) {}

    public function createDesign(
        User $actor,
        IntegrationConnection $connection,
        CanvaDesignRequest $request,
        string $idempotencyKey,
        ?ContentItem $content = null,
        ?Asset $asset = null,
        ?AgentExecution $execution = null,
        ?string $correlationId = null,
    ): ExternalResource {
        $enterprise = $content !== null ? $content->enterprise : ($asset !== null ? $asset->enterprise : $execution?->enterprise);

        if ($enterprise === null
            || (int) $connection->organization_id !== (int) $enterprise->organization_id
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $enterprise->id)
        ) {
            throw new AuthorizationException('The Canva connection is outside the target organization or enterprise.');
        }

        if ($content !== null) {
            Gate::forUser($actor)->authorize('view', $content);
        }

        if ($asset !== null) {
            Gate::forUser($actor)->authorize('view', $asset);
        }

        if ($execution !== null && ((int) $execution->organization_id !== (int) $enterprise->organization_id
            || (int) $execution->enterprise_id !== (int) $enterprise->id
        )) {
            throw new AuthorizationException('The Agent execution is outside the target organization or enterprise.');
        }

        if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
            throw new AuthorizationException('The Canva connection is disabled.');
        }

        $correlationId ??= app(ExecutionCorrelationService::class)->resolve();

        $job = IntegrationJob::query()->firstOrCreate(
            [
                'provider' => 'canva',
                'operation' => 'design.create',
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'integration_connection_id' => $connection->id,
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->id,
                'content_item_id' => $content?->id,
                'asset_id' => $asset?->id,
                'agent_execution_id' => $execution?->id,
                'status' => IntegrationJob::STATUS_PENDING,
                'correlation_id' => $correlationId,
            ],
        );

        if ((int) $job->integration_connection_id !== (int) $connection->id
            || (int) $job->enterprise_id !== (int) $enterprise->id
        ) {
            throw new AuthorizationException('The idempotency key belongs to a different Canva operation scope.');
        }

        if ($job->status === IntegrationJob::STATUS_SUCCEEDED) {
            $existing = ExternalResource::query()
                ->where('provider', 'canva')
                ->where('resource_type', 'design')
                ->where('correlation_id', $job->correlation_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $job->attempts++;
        $job->failure_code = null;
        $job->failure_reason = null;
        $job->status = IntegrationJob::STATUS_RUNNING;
        $job->save();

        try {
            $result = $this->client->createDesign($connection, $request);

            $resource = ExternalResource::query()->firstOrCreate(
                [
                    'provider' => 'canva',
                    'resource_type' => 'design',
                    'external_id' => $result->externalId,
                ],
                [
                    'integration_connection_id' => $connection->id,
                    'organization_id' => $enterprise->organization_id,
                    'enterprise_id' => $enterprise->id,
                    'content_item_id' => $content?->id,
                    'asset_id' => $asset?->id,
                    'agent_execution_id' => $execution?->id,
                    'external_url' => $result->externalUrl,
                    'correlation_id' => $job->correlation_id,
                    'metadata' => $result->metadata,
                ],
            );

            $job->external_job_id = $result->externalId;
            $job->transitionTo(IntegrationJob::STATUS_SUCCEEDED)->save();

            return $resource;
        } catch (CanvaClientException $e) {
            $job->fail($e->getMessage(), $e->failureCode)->save();
            throw $e;
        }
    }
}