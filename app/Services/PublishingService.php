<?php

namespace App\Services;

use App\AI\Contracts\FailureProvenance;
use App\Contracts\PublishingStatusLookupAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResolution;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingRemotePublication;
use App\Exceptions\PublishingAdapterException;
use App\Experts\MarketingExpert;
use App\Jobs\ReconcilePublicationStatusJob;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\IntegrationConnection;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\PublicationSchedule;
use App\Models\PublishingJob;
use App\Models\SocialAccount;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class PublishingService
{
    public function __construct(
        private readonly PublishingAdapterRegistry $adapters,
        private readonly AgentCapabilityAuthorizer $capabilities,
        private readonly FailureTranslator $failures,
    ) {}

    public function schedule(
        User $actor,
        ContentItem $content,
        SocialAccount $account,
        \DateTimeInterface $at,
        ?ApprovalRequest $approval = null,
        ?AgentAssignment $assignment = null,
        ?AgentExecution $execution = null,
    ): Publication {
        $this->resolveDestination($content, $account);
        $this->authorizePublication($actor, $content, $approval, $assignment, $execution);

        return DB::transaction(function () use ($content, $account, $at, $approval) {
            $publication = Publication::query()->create([
                'enterprise_id' => $content->enterprise_id,
                'content_item_id' => $content->id,
                'channel_id' => $account->channel_id,
                'social_account_id' => $account->id,
                'approval_request_id' => $approval?->id,
                'status' => Publication::STATUS_SCHEDULED,
                'idempotency_key' => 'publication-'.Str::uuid(),
                'correlation_id' => app(ExecutionCorrelationService::class)->resolve(),
                'scheduled_at' => $at,
            ]);

            PublicationSchedule::query()->create([
                'enterprise_id' => $content->enterprise_id,
                'publication_id' => $publication->id,
                'scheduled_at' => $at,
                'status' => PublicationSchedule::STATUS_SCHEDULED,
            ]);

            return $publication;
        });
    }

    public function submit(
        User $actor,
        Publication $publication,
        ?AgentAssignment $assignment = null,
        ?AgentExecution $execution = null,
        ?ApprovalRequest $approval = null,
    ): Publication {
        $publication->loadMissing(['contentItem', 'socialAccount.channel', 'channel', 'approvalRequest']);

        /** @var ContentItem $content */
        $content = $publication->contentItem;
        /** @var SocialAccount $account */
        $account = $publication->socialAccount;

        $this->resolveDestination($content, $account, $publication);
        $this->authorizePublication($actor, $content, $approval ?? $publication->approvalRequest, $assignment, $execution);

        if (in_array($publication->status, [Publication::STATUS_SUBMITTED, Publication::STATUS_SUCCEEDED], true)) {
            return $publication;
        }
        if ($publication->status === Publication::STATUS_UNKNOWN) {
            throw new LogicException('The publication outcome is ambiguous and must be reconciled before retrying.');
        }

        $destination = $this->resolveDestination($content, $account, $publication, requireConnection: true);
        $resolution = $destination['resolution'];
        $connection = $destination['connection'];

        if ($publication->scheduled_at !== null && $publication->scheduled_at->isFuture() && ! $resolution->useNativeScheduling) {
            throw new LogicException('CR8OR must wait until the scheduled time before submitting to this destination.');
        }

        $job = PublishingJob::query()->firstOrCreate(
            ['idempotency_key' => 'publish-'.$publication->idempotency_key],
            [
                'enterprise_id' => $publication->enterprise_id,
                'publication_id' => $publication->id,
                'status' => PublishingJob::STATUS_PENDING,
            ],
        );

        if ($job->status === PublishingJob::STATUS_UNKNOWN) {
            throw new LogicException('The publishing job has an ambiguous remote outcome and must be reconciled before retrying.');
        }

        if ($job->status === PublishingJob::STATUS_FAILED) {
            if (! $job->retryable) {
                throw new LogicException('The previous publishing failure is not classified as retryable.');
            }

            $job->status = PublishingJob::STATUS_PENDING;
            $job->failure_code = null;
            $job->failure_reason = null;
            $job->completed_at = null;
        }

        if (($job->adapter_key !== null && $job->adapter_key !== $resolution->adapterKey)
            || ($job->network !== null && $job->network !== $resolution->network)
            || ($job->destination_external_id !== null && $job->destination_external_id !== $destination['destination_external_id'])) {
            throw new LogicException('A retry cannot change the adapter or destination of an existing publishing job.');
        }

        if (! $resolution->capabilities->supportsIdempotency) {
            throw new PublishingAdapterException('The adapter does not provide a safe idempotency strategy.', 'idempotency_unsupported');
        }

        $job->adapter_key = $resolution->adapterKey;
        $job->network = $resolution->network;
        $job->destination_external_id = $destination['destination_external_id'];
        $job->start()->save();

        try {
            $request = new PublishingAdapterRequest(
                network: $resolution->network,
                destinationExternalId: $destination['destination_external_id'],
                content: (string) $content->body,
                media: $destination['media'],
                scheduledAt: $resolution->useNativeScheduling
                    ? $publication->scheduled_at?->toIso8601String() ?? now()->toIso8601String()
                    : now()->toIso8601String(),
                idempotencyKey: $publication->idempotency_key,
                correlationId: (string) $publication->correlation_id,
                integrationConnectionId: $connection === null ? null : (int) $connection->id,
                credentialReference: $connection?->credential_reference,
                settings: ['__type' => $publication->channel->type, 'title' => (string) $content->title, 'metadata' => $content->getAttribute('metadata') ?? []],
                publishingJobId: (int) $job->id,
            );
            $result = $resolution->adapter->publish($request);
            $this->assertValidAdapterResult($result, $request);

            $job->external_id = $result->externalId;
            $job->external_url = $result->externalUrl;
            $job->save();

            $publication->markSubmitted($result->externalId, $result->externalUrl)->save();
            if (in_array($result->status, ['published', 'succeeded'], true)) {
                $publication->succeed()->save();
            }
            $job->succeed()->save();
            $this->record($publication, $job, $resolution->adapterKey, $resolution->network, $result);
            if ($result->status === 'pending' && $resolution->adapter instanceof PublishingStatusLookupAdapter) {
                ReconcilePublicationStatusJob::dispatch((int) $publication->id)
                    ->onConnection((string) config('publishing.status_reconciliation.queue_connection', 'database'))
                    ->delay(now()->addSeconds(max(1, (int) config('publishing.status_reconciliation.initial_delay_seconds', 60))));
            }

            return $publication->refresh();
        } catch (PublishingAdapterException $exception) {
            if ($connection !== null
                && in_array($exception->failureCode, ['authentication_required', 'invalid_grant', 'token_expired'], true)
                && $connection->status === IntegrationConnection::STATUS_ACTIVE) {
                $connection->transitionTo(IntegrationConnection::STATUS_RECONNECT_REQUIRED)->save();
            }

            $failure = $this->failures->translate(
                $exception,
                correlationId: $publication->correlation_id,
                provenance: new FailureProvenance(
                    operation: 'publication.publish',
                    capability: 'integration.publishing.publication.publish',
                ),
            );

            if ($exception->ambiguous) {
                $job->markUnknown($failure->code, $failure->message)->save();
                $publication->markUnknown($failure->code, $failure->message)->save();
                $this->recordFailure($publication, $job, $resolution, $exception, 'unknown');
            } else {
                $job->fail($failure->message, $failure->code);
                $job->retryable = $exception->retryable;
                $job->save();
                $publication->fail($failure->code, $failure->message)->save();
                $this->recordFailure($publication, $job, $resolution, $exception, 'failed');
            }

            throw $exception;
        }
    }

    public function reconcileStatus(Publication $publication): ?string
    {
        if ($publication->status === Publication::STATUS_SUCCEEDED) {
            return 'published';
        }
        if ($publication->status === Publication::STATUS_FAILED) {
            return 'failed';
        }

        $publication->loadMissing(['contentItem', 'socialAccount.channel', 'socialAccount.socialIdentity', 'channel']);
        $job = $publication->publishingJobs()->latest('id')->first();
        if ($job === null) {
            return null;
        }
        $state = $job->adapterState();
        $network = $job->network ?? $publication->target_provider ?? $publication->socialAccount->provider;
        $externalPublicationId = $job->external_id;
        if (! is_string($externalPublicationId) || trim($externalPublicationId) === '') {
            $externalPublicationId = $network === 'threads' && is_string($state['container_id'] ?? null) ? $state['container_id'] : null;
        }
        if ($externalPublicationId === null || trim($externalPublicationId) === '') {
            return null;
        }

        $content = $publication->contentItem;
        $account = $publication->socialAccount;
        $network = $job->network ?? $publication->target_provider ?? $account->provider;
        $destinationExternalId = $job->destination_external_id ?? $publication->target_external_id ?? $account->external_id;
        if ((int) $content->enterprise_id !== (int) $publication->enterprise_id
            || (int) $account->enterprise_id !== (int) $publication->enterprise_id
            || $account->provider !== $network
            || ! hash_equals((string) $account->external_id, (string) $destinationExternalId)
            || ($publication->target_provider !== null && $publication->target_provider !== $network)
            || ($publication->target_external_id !== null && ! hash_equals((string) $publication->target_external_id, (string) $destinationExternalId))) {
            throw new LogicException('Publication status reconciliation does not match the immutable publication destination and tenancy.');
        }

        $resolution = $this->adapters->resolve($network);
        if (! $resolution->adapter instanceof PublishingStatusLookupAdapter) {
            return null;
        }
        $connection = $this->resolveConnection($account, $network);
        $metadata = [];
        if (in_array($state['requested_privacy_status'] ?? null, ['private', 'unlisted', 'public'], true)) {
            $metadata['requested_privacy_status'] = $state['requested_privacy_status'];
        }
        if ($network === 'threads') {
            $metadata['adapter_state'] = array_intersect_key($state, array_flip(['container_id', 'phase', 'media_type', 'post_id', 'destination_external_id', 'request_fingerprint']));
        }

        try {
            $result = $resolution->adapter->lookupStatus(new PublishingRemotePublication(
                network: $network,
                destinationExternalId: $destinationExternalId,
                externalPublicationId: $externalPublicationId,
                idempotencyKey: $publication->idempotency_key,
                correlationId: (string) $publication->correlation_id,
                integrationConnectionId: (int) $connection->id,
                credentialReference: $connection->credential_reference,
                metadata: $metadata,
                publishingJobId: (int) $job->id,
            ));
        } catch (PublishingAdapterException $exception) {
            if (! $exception->ambiguous) {
                throw $exception;
            }

            DB::transaction(function () use ($publication, $job, $resolution, $exception): void {
                if ($publication->status !== Publication::STATUS_UNKNOWN) {
                    $publication->markUnknown($exception->failureCode, $exception->getMessage())->save();
                }
                $this->recordFailure($publication, $job, $resolution, $exception, 'unknown');
            });

            return 'unknown';
        }

        if ($result === null) {
            return null;
        }
        if ($result->status === 'pending') {
            return 'pending';
        }

        $this->reconcile($publication, $result);

        return $result->status;
    }

    public function reconcile(Publication $publication, PublishingAdapterResult $result): Publication
    {
        if ($result->correlationId !== (string) $publication->correlation_id
            || $result->idempotencyKey !== $publication->idempotency_key) {
            throw new LogicException('A reconciled publishing result must match the publication correlation and idempotency identity.');
        }

        if (in_array($publication->status, [Publication::STATUS_SUCCEEDED, Publication::STATUS_FAILED], true)) {
            return $publication;
        }

        $job = $publication->publishingJobs()->latest('id')->first();
        if ($job === null) {
            throw new LogicException('Publication reconciliation requires a correlated publishing job.');
        }

        if ($result->status === 'failed') {
            $failureCode = $result->payload['failure_code'] ?? null;
            if (! is_string($failureCode) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $failureCode) !== 1) {
                $failureCode = 'provider_rejected';
            }
            $failureReason = $result->payload['failure_reason'] ?? null;
            if (! is_string($failureReason) || trim($failureReason) === '') {
                $failureReason = 'The network confirmed publication failure.';
            }
            $failureReason = mb_substr($failureReason, 0, 1000);
            $publication->fail($failureCode, $failureReason)->save();
            if ($job->status !== PublishingJob::STATUS_SUCCEEDED) {
                $job->fail($failureReason, $failureCode)->save();
            }
        } elseif (in_array($result->status, ['submitted', 'accepted', 'pending', 'published', 'succeeded'], true)) {
            if (trim($result->externalId) === '') {
                throw new LogicException('A reconciled result must include its external publication identifier.');
            }

            if (in_array($publication->status, [Publication::STATUS_SCHEDULED, Publication::STATUS_UNKNOWN], true)) {
                $publication->markSubmitted($result->externalId, $result->externalUrl)->save();
            }
            if (in_array($result->status, ['published', 'succeeded'], true)) {
                $publication->succeed()->save();
            }

            $job->external_id = $result->externalId;
            $job->external_url = $result->externalUrl;
            if ($job->status !== PublishingJob::STATUS_SUCCEEDED) {
                $job->succeed()->save();
            } else {
                $job->save();
            }
        } else {
            throw new LogicException('Unsupported reconciliation status.');
        }

        $network = $job->network ?? $publication->target_provider;
        $adapterKey = $job->adapter_key ?? $publication->results()->latest('id')->value('provider') ?? $network;
        if (! is_string($network) || $network === '' || ! is_string($adapterKey) || $adapterKey === '') {
            throw new LogicException('Publication history is missing its original network or adapter identity.');
        }

        $this->record($publication, $job, $adapterKey, $network, $result);

        return $publication->refresh();
    }

    /**
     * @return array{resolution: PublishingAdapterResolution, connection: IntegrationConnection|null, destination_external_id: string, media: list<array<string, mixed>>}
     */
    private function resolveDestination(
        ContentItem $content,
        SocialAccount $account,
        ?Publication $publication = null,
        bool $requireConnection = false,
    ): array {
        if ($content->status !== ContentItem::STATUS_PUBLICATION_READY) {
            throw new LogicException('Only publication-ready content can enter the publishing lifecycle.');
        }

        if ($account->status !== SocialAccount::STATUS_ACTIVE || $account->channel->status !== Channel::STATUS_ACTIVE) {
            throw new LogicException('The social account and channel must both be active.');
        }

        if ((int) $content->enterprise_id !== (int) $account->enterprise_id
            || (int) $account->channel->enterprise_id !== (int) $account->enterprise_id) {
            throw new LogicException('Content, account, and channel must belong to the same enterprise.');
        }

        $network = $publication?->target_provider ?: $account->provider;
        $destinationExternalId = $publication?->target_external_id ?: $account->external_id;
        if ($publication !== null
            && (($publication->target_provider !== null && $publication->target_provider !== $account->provider)
                || ($publication->target_external_id !== null && $publication->target_external_id !== $account->external_id))) {
            throw new LogicException('The account no longer matches the immutable publication destination.');
        }

        $resolution = $this->adapters->resolve($network);
        $connection = $requireConnection ? $this->resolveConnection($account, $network) : null;
        $media = $this->publicationMedia($content);
        $this->validateCapabilities($content, $media, $resolution);

        return [
            'resolution' => $resolution,
            'connection' => $connection,
            'destination_external_id' => $destinationExternalId,
            'media' => $media,
        ];
    }

    private function resolveConnection(SocialAccount $account, string $network): IntegrationConnection
    {
        if ($account->social_identity_id === null) {
            throw new LogicException('A native social destination requires an authorized identity and active connection.');
        }

        $identity = $account->socialIdentity()->with('integrationConnection')->first();
        if (! $identity instanceof SocialIdentity || (int) $identity->organization_id !== (int) $account->enterprise->organization_id) {
            throw new LogicException('The social destination identity is outside the publication organization.');
        }

        $authorizedNetworks = config("social_authorization.providers.{$identity->provider}.destination_networks", []);
        if (! is_array($authorizedNetworks) || ! in_array($network, $authorizedNetworks, true)) {
            throw new LogicException('The social identity grant does not authorize this destination network.');
        }

        $connection = $identity->integrationConnection;
        if ($connection === null || ! $connection->isOperational()) {
            throw new LogicException('The social destination requires an active authorization connection.');
        }

        if ((int) $connection->organization_id !== (int) $identity->organization_id
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $account->enterprise_id)
            || $connection->provider !== $identity->provider) {
            throw new LogicException('The social authorization connection does not match the publication tenancy.');
        }

        return $connection;
    }

    /** @return list<array<string, mixed>> */
    private function publicationMedia(ContentItem $content): array
    {
        $assets = $content->assets()
            ->where('status', Asset::STATUS_ACTIVE)
            ->with(['versions' => fn ($query) => $query->with('mediaMetadata')->orderByDesc('version')])
            ->get();
        $media = [];

        foreach ($assets as $asset) {
            $version = $asset->versions->first();
            if ($version === null || ! is_string($version->mime_type) || trim($version->mime_type) === '') {
                throw new LogicException('An active content asset must have a version with a declared MIME type before publication.');
            }

            $videoWidth = data_get($version, 'mediaMetadata.width');
            $videoHeight = data_get($version, 'mediaMetadata.height');
            $videoDuration = data_get($version, 'mediaMetadata.duration_seconds');
            $videoCodec = data_get($version, 'mediaMetadata.codec');
            $videoFrameRate = data_get($version, 'mediaMetadata.frame_rate');
            $versionMetadataValue = $version->getAttribute('metadata');
            $versionMetadata = is_array($versionMetadataValue) ? $versionMetadataValue : [];
            /** @var array<string, mixed> $versionMetadata */
            $metadata = array_merge($versionMetadata, array_filter([
                'width' => $videoWidth,
                'height' => $videoHeight,
                'duration_seconds' => $videoDuration,
                'codec' => $videoCodec,
                'frame_rate' => $videoFrameRate,
            ], static fn (mixed $value): bool => $value !== null));

            $media[] = [
                'asset_id' => (int) $asset->id,
                'mime_type' => $version->mime_type,
                'size' => (int) $version->size,
                'checksum' => $version->checksum,
                'disk' => $version->disk,
                'path' => $version->path,
                'external_reference' => $version->external_reference,
                'metadata' => $metadata,
                'dimensions' => is_numeric($videoWidth) && is_numeric($videoHeight)
                    ? ['width' => (int) $videoWidth, 'height' => (int) $videoHeight]
                    : (is_array($asset->dimensions) ? $asset->dimensions : []),
                'duration_seconds' => is_numeric($videoDuration)
                    ? (float) $videoDuration
                    : ($asset->duration_seconds === null ? null : (float) $asset->duration_seconds),
                'codec' => is_string($videoCodec) ? $videoCodec : ($versionMetadata['codec'] ?? null),
                'frame_rate' => is_numeric($videoFrameRate) ? (float) $videoFrameRate : ($versionMetadata['frame_rate'] ?? null),
            ];
        }

        return $media;
    }

    /** @param list<array<string, mixed>> $media */
    private function validateCapabilities(ContentItem $content, array $media, PublishingAdapterResolution $resolution): void
    {
        $capabilities = $resolution->capabilities;
        if ($capabilities->maxTextLength !== null && mb_strlen((string) $content->body) > $capabilities->maxTextLength) {
            throw new PublishingAdapterException('Content exceeds the destination text limit.', 'content_too_long');
        }

        if ($capabilities->maxMediaItems !== null && count($media) > $capabilities->maxMediaItems) {
            throw new PublishingAdapterException('The publication exceeds the destination media-count limit.', 'too_many_media_items');
        }

        foreach ($media as $item) {
            $mimeType = $item['mime_type'] ?? null;
            if (! is_string($mimeType) || ! $capabilities->supportsMediaType($mimeType)) {
                throw new PublishingAdapterException('The adapter does not support one or more attached media types.', 'unsupported_media');
            }

            $size = $item['size'] ?? null;
            if ($capabilities->maxMediaSizeBytes !== null && (! is_numeric($size) || (int) $size > $capabilities->maxMediaSizeBytes)) {
                throw new PublishingAdapterException('Attached media exceeds the destination file-size limit or lacks size metadata.', 'media_size_exceeded');
            }

            if (str_starts_with($mimeType, 'video/') && $capabilities->maxVideoDurationSeconds !== null) {
                $metadata = $item['metadata'] ?? [];
                $duration = $item['duration_seconds'] ?? (is_array($metadata) ? ($metadata['duration_seconds'] ?? null) : null);
                if (! is_numeric($duration)) {
                    throw new PublishingAdapterException('Video duration metadata is required by the destination capability contract.', 'media_duration_missing');
                }
                if ((float) $duration > $capabilities->maxVideoDurationSeconds) {
                    throw new PublishingAdapterException('Video exceeds the destination duration limit.', 'video_too_long');
                }
            }

            if ($capabilities->supportedAspectRatios !== []) {
                $dimensions = $item['dimensions'] ?? [];
                if ((! is_array($dimensions) || $dimensions === []) && is_array($item['metadata'] ?? null)) {
                    $dimensions = $item['metadata']['dimensions'] ?? [];
                }
                $width = is_array($dimensions) ? ($dimensions['width'] ?? null) : null;
                $height = is_array($dimensions) ? ($dimensions['height'] ?? null) : null;
                if (! is_numeric($width) || ! is_numeric($height) || (int) $width < 1 || (int) $height < 1) {
                    throw new PublishingAdapterException('Media dimensions are required by the destination aspect-ratio contract.', 'media_dimensions_missing');
                }

                $aspectRatio = $this->normalizedAspectRatio((int) $width, (int) $height);
                if (! in_array($aspectRatio, $capabilities->supportedAspectRatios, true)) {
                    throw new PublishingAdapterException('Media aspect ratio is not supported by the destination.', 'unsupported_aspect_ratio');
                }
            }
        }

        if (! $capabilities->supportsIdempotency) {
            throw new PublishingAdapterException('The adapter does not provide a safe idempotency strategy.', 'idempotency_unsupported');
        }
    }

    private function normalizedAspectRatio(int $width, int $height): string
    {
        $a = $width;
        $b = $height;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return ((int) ($width / $a)).':'.((int) ($height / $a));
    }

    private function assertValidAdapterResult(PublishingAdapterResult $result, PublishingAdapterRequest $request): void
    {
        if (! in_array($result->status, ['submitted', 'accepted', 'pending', 'published', 'succeeded'], true)
            || trim($result->externalId) === ''
            || $result->correlationId !== $request->correlationId
            || $result->idempotencyKey !== $request->idempotencyKey) {
            throw new PublishingAdapterException('The adapter returned an invalid or uncorrelated outcome.', 'invalid_adapter_response', ambiguous: true);
        }
    }

    private function authorizePublication(
        User $actor,
        ContentItem $content,
        ?ApprovalRequest $approval,
        ?AgentAssignment $assignment,
        ?AgentExecution $execution,
    ): void {
        if (($assignment === null) !== ($execution === null)) {
            throw new AuthorizationException('Agent publication requires both assignment and execution context.');
        }

        if ($assignment === null) {
            return;
        }

        if (! $this->capabilities->allowsExpertCapability(
            $assignment,
            'marketing',
            new MarketingExpert,
            'publication.publish',
            $content->enterprise->organization,
            $content->enterprise,
            $actor,
            $approval,
            $execution,
            ['content_item_id' => $content->id],
        )) {
            throw new AuthorizationException('The Agent is not authorized to publish this content.');
        }
    }

    private function record(
        Publication $publication,
        PublishingJob $job,
        string $adapterKey,
        string $network,
        PublishingAdapterResult $result,
    ): PublicationResult {
        $destinationExternalId = $job->destination_external_id ?? $publication->target_external_id;

        return PublicationResult::query()->create([
            'enterprise_id' => $publication->enterprise_id,
            'publication_id' => $publication->id,
            'publishing_job_id' => $job->id,
            'provider' => $adapterKey,
            'network' => $network,
            'destination_external_id' => $destinationExternalId,
            'provider_status' => $result->status,
            'external_id' => $result->externalId,
            'external_url' => $result->externalUrl,
            'correlation_id' => $publication->correlation_id,
            'failure_code' => $result->status === 'failed' ? 'provider_rejected' : null,
            'failure_reason' => $result->status === 'failed' ? 'The network confirmed publication failure.' : null,
            'payload' => [
                ...app(PublishingResultPayloadSanitizer::class)->sanitize($result->payload),
                'network' => $network,
                'adapter_key' => $adapterKey,
                'destination_external_id' => $destinationExternalId,
                'idempotency_key' => $publication->idempotency_key,
            ],
            'recorded_at' => now(),
        ]);
    }

    private function recordFailure(
        Publication $publication,
        PublishingJob $job,
        PublishingAdapterResolution $resolution,
        PublishingAdapterException $exception,
        string $providerStatus,
    ): PublicationResult {
        $destinationExternalId = $job->destination_external_id ?? $publication->target_external_id;

        return PublicationResult::query()->create([
            'enterprise_id' => $publication->enterprise_id,
            'publication_id' => $publication->id,
            'publishing_job_id' => $job->id,
            'provider' => $resolution->adapterKey,
            'network' => $resolution->network,
            'destination_external_id' => $destinationExternalId,
            'provider_status' => $providerStatus,
            'correlation_id' => $publication->correlation_id,
            'failure_code' => $exception->failureCode,
            'failure_reason' => $exception->getMessage(),
            'payload' => [
                'network' => $resolution->network,
                'adapter_key' => $resolution->adapterKey,
                'destination_external_id' => $destinationExternalId,
                'idempotency_key' => $publication->idempotency_key,
                'ambiguous' => $exception->ambiguous,
                'retryable' => $exception->retryable && ! $exception->ambiguous,
            ],
            'recorded_at' => now(),
        ]);
    }
}