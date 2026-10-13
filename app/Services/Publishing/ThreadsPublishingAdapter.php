<?php

namespace App\Services\Publishing;

use App\Contracts\CredentialResolver;
use App\Contracts\PublishingStatusLookupAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;
use App\Data\PublishingRemotePublication;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Models\PublishingJob;
use App\Services\Social\SocialCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ThreadsPublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_TEXT_LENGTH = 500;

    private const MAX_IMAGE_BYTES = 8_388_608;

    private const MAX_VIDEO_BYTES = 1_073_741_824;

    private const MAX_VIDEO_DURATION_SECONDS = 300;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
    ) {}

    public function network(): string
    {
        return 'threads';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'threads';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'threads',
            supportedAccountTypes: ['threads.user'],
            supportedDestinationTypes: ['threads.user'],
            supportedMediaTypes: ['image/jpeg', 'image/png', 'video/mp4', 'video/quicktime'],
            maxTextLength: self::MAX_TEXT_LENGTH,
            maxMediaItems: 1,
            maxMediaSizeBytes: self::MAX_VIDEO_BYTES,
            maxVideoDurationSeconds: self::MAX_VIDEO_DURATION_SECONDS,
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported,
            editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported,
            analytics: PublishingCapabilitySupport::Unsupported,
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The Threads adapter received a request for another network.', 'network_mismatch');
        }

        $userId = $this->threadsUserId($request->destinationExternalId);
        $media = $this->validatedMedia($request->media);
        $settings = $this->validatedSettings($request->settings);
        if (mb_strlen($request->content) > self::MAX_TEXT_LENGTH) {
            throw new PublishingAdapterException('Threads post text cannot exceed 500 characters; long-form text attachments are not enabled.', 'content_too_long');
        }
        if ($media === [] && trim($request->content) === '') {
            throw new PublishingAdapterException('Threads text-only posts require non-empty text.', 'content_required');
        }

        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference, $userId);
        $job = $this->resolveJob($request, $connection);
        $token = $this->accessToken($connection, ['threads_basic', 'threads_content_publish']);
        $mediaType = $media === [] ? 'TEXT' : (str_starts_with((string) $media[0]['mime_type'], 'image/') ? 'IMAGE' : 'VIDEO');
        $fingerprint = $this->requestFingerprint($request->content, $media, $settings, $mediaType);
        $state = $job->adapterState();

        if ($state !== []) {
            $this->assertMatchingState($state, $userId, $fingerprint, $mediaType);
            if (($state['phase'] ?? null) === 'published' && is_string($state['post_id'] ?? null)) {
                return $this->result($request, $userId, $state['post_id'], 'published', $state);
            }
            if (($state['phase'] ?? null) === 'publishing') {
                throw new PublishingAdapterException('The Threads publish request has an ambiguous outcome; automatic resubmission is blocked to prevent duplicates.', 'ambiguous_publish_outcome', ambiguous: true);
            }
        } else {
            $containerParams = ['media_type' => $mediaType];
            if (trim($request->content) !== '') {
                $containerParams['text'] = $request->content;
            }
            if ($media !== []) {
                $containerParams[$mediaType === 'IMAGE' ? 'image_url' : 'video_url'] = $this->temporaryMediaUrl($media[0]);
                $altText = $this->altText($media[0]);
                if ($altText !== null) {
                    $containerParams['alt_text'] = $altText;
                }
            }
            if ($settings['topic_tag'] !== null) {
                $containerParams['topic_tag'] = $settings['topic_tag'];
            }
            if ($settings['reply_control'] !== null) {
                $containerParams['reply_control'] = $settings['reply_control'];
            }

            $containerId = $this->createContainer($userId, $token, $containerParams);
            $state = [
                'phase' => 'waiting_container',
                'container_id' => $containerId,
                'media_type' => $mediaType,
                'destination_external_id' => $userId,
                'request_fingerprint' => $fingerprint,
                'created_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $job->setAdapterState($state)->save();
        }

        return $this->advanceContainer($request, $connection, $token, $job, $state);
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The Threads adapter received a status lookup for another network.', 'network_mismatch');
        }

        $userId = $this->threadsUserId($publication->destinationExternalId);
        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference, $userId);
        $job = $this->resolveRemoteJob($publication, $connection);
        $state = $publication->metadata['adapter_state'] ?? [];
        if (! is_array($state)) {
            $state = [];
        }
        if (($state['phase'] ?? null) === 'publishing') {
            throw new PublishingAdapterException('The Threads publish request has an ambiguous outcome; automatic resubmission is blocked to prevent duplicates.', 'ambiguous_publish_outcome', ambiguous: true);
        }
        $requiredScopes = $state === [] || ($state['phase'] ?? null) === 'published'
            ? ['threads_basic']
            : ['threads_basic', 'threads_content_publish'];
        $token = $this->accessToken($connection, $requiredScopes);
        if ($state === []) {
            return $this->lookupPublishedPost($publication, $connection, $userId, $token);
        }

        $this->assertMatchingState(
            $state,
            $userId,
            is_string($state['request_fingerprint'] ?? null) ? $state['request_fingerprint'] : '',
            is_string($state['media_type'] ?? null) ? $state['media_type'] : 'TEXT',
            allowMissingFingerprint: true,
        );
        if (($state['phase'] ?? null) === 'published' && is_string($state['post_id'] ?? null)) {
            return $this->lookupPublishedPost($publication, $connection, $userId, $token, $state['post_id']);
        }
        $containerId = $state['container_id'] ?? $publication->externalPublicationId;
        if (! is_string($containerId) || preg_match('/^[0-9]{1,64}$/D', $containerId) !== 1) {
            throw new PublishingAdapterException('Threads status lookup requires a valid media-container identifier.', 'invalid_adapter_response');
        }
        $state['container_id'] = $containerId;
        $state['destination_external_id'] = $userId;
        $state['phase'] = 'waiting_container';
        $this->persistState($job, $state);

        return $this->advanceContainer($publication, $connection, $token, $job, $state);
    }

    /** @param array<string, mixed> $params */
    private function createContainer(string $userId, string $token, array $params): string
    {
        try {
            $response = Http::asForm()->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->post($this->baseUrl().'/'.rawurlencode($userId).'/threads', $params);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Threads did not return a definitive media-container response; the outcome is unknown and must not be retried automatically.', 'timeout', retryable: true, previous: $exception, ambiguous: true);
        }

        $body = $this->assertApiSuccess($response, submission: true);
        $id = $body['id'] ?? null;
        if (! is_string($id) || preg_match('/^[0-9]{1,64}$/D', $id) !== 1) {
            throw new PublishingAdapterException('Threads accepted container creation without returning a valid container ID; reconcile before retrying.', 'invalid_adapter_response', retryable: true, ambiguous: true);
        }

        return $id;
    }

    /** @param array<string, mixed> $state */
    private function advanceContainer(PublishingAdapterRequest|PublishingRemotePublication $request, IntegrationConnection $connection, string $token, PublishingJob $job, array $state): PublishingAdapterResult
    {
        $userId = (string) ($state['destination_external_id'] ?? $request->destinationExternalId);
        $containerId = $state['container_id'] ?? null;
        if (! is_string($containerId) || preg_match('/^[0-9]{1,64}$/D', $containerId) !== 1) {
            throw new PublishingAdapterException('Threads publishing state does not contain a valid container ID.', 'invalid_adapter_response');
        }
        $status = $this->containerStatus($containerId, $token);
        if ($status === null) {
            return $this->result($request, $userId, $containerId, 'pending', $state, ['container_status' => 'UNKNOWN']);
        }
        if ($status === 'IN_PROGRESS') {
            return $this->result($request, $userId, $containerId, 'pending', $state, ['container_status' => $status]);
        }
        if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
            if ($request instanceof PublishingAdapterRequest) {
                throw new PublishingAdapterException('Threads media-container processing failed or expired before publication.', 'container_failed');
            }

            return $this->result($request, $userId, $containerId, 'failed', $state, ['container_status' => $status, 'failure_code' => 'container_failed']);
        }
        if ($status === 'PUBLISHED' || ($state['phase'] ?? null) === 'publishing') {
            throw new PublishingAdapterException('Threads reports a container whose publish request may already have completed; automatic resubmission is blocked to prevent duplicates.', 'ambiguous_publish_outcome', ambiguous: true);
        }
        if ($status !== 'FINISHED') {
            throw new PublishingAdapterException('Threads returned an unsupported media-container status.', 'invalid_adapter_response');
        }

        $state['phase'] = 'publishing';
        $this->persistState($job, $state);
        try {
            $response = Http::asForm()->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->post($this->baseUrl().'/'.rawurlencode($userId).'/threads_publish', ['creation_id' => $containerId]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Threads did not return a definitive publish response; the outcome is unknown and the container will not be submitted again automatically.', 'timeout', retryable: true, previous: $exception, ambiguous: true);
        }

        try {
            $body = $this->assertApiSuccess($response, submission: true);
        } catch (PublishingAdapterException $exception) {
            if ($exception->retryable && ! $exception->ambiguous) {
                $state['phase'] = 'waiting_container';
                $this->persistState($job, $state);
            }
            throw $exception;
        }
        $postId = $body['id'] ?? null;
        if (! is_string($postId) || preg_match('/^[0-9]{1,64}$/D', $postId) !== 1) {
            throw new PublishingAdapterException('Threads accepted publication without returning a valid post ID; the outcome is unknown and must not be retried automatically.', 'invalid_adapter_response', retryable: true, ambiguous: true);
        }

        $state['phase'] = 'published';
        $state['post_id'] = $postId;
        $this->persistState($job, $state);

        $username = $connection->socialIdentity?->name;
        $permalink = $this->postPermalink($postId, $token, is_string($username) ? $username : null);

        return $this->result($request, $userId, $postId, 'published', $state, ['container_status' => 'PUBLISHED'], $permalink);
    }

    private function containerStatus(string $containerId, string $token): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->get($this->baseUrl().'/'.rawurlencode($containerId), ['fields' => 'id,status,error_message']);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Threads media-container processing status could not be verified.', 'timeout', retryable: true, previous: $exception);
        }
        if ($response->status() === 404) {
            return null;
        }
        $body = $this->assertApiSuccess($response, submission: false);
        if (($body['id'] ?? null) !== $containerId || ! is_string($body['status'] ?? null)) {
            throw new PublishingAdapterException('Threads returned an invalid media-container status response.', 'invalid_adapter_response');
        }

        return $body['status'];
    }

    private function lookupPublishedPost(PublishingRemotePublication $publication, IntegrationConnection $connection, string $userId, string $token, ?string $postId = null): ?PublishingAdapterResult
    {
        $postId ??= $publication->externalPublicationId;
        if (preg_match('/^[0-9]{1,64}$/D', $postId) !== 1) {
            throw new PublishingAdapterException('Threads status lookup requires a numeric post ID.', 'invalid_destination');
        }
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->get($this->baseUrl().'/'.rawurlencode($postId), ['fields' => 'id,permalink,text,timestamp,media_type,username']);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Threads post status could not be retrieved.', 'timeout', retryable: true, previous: $exception);
        }
        if ($response->status() === 404) {
            return null;
        }
        $body = $this->assertApiSuccess($response, submission: false);
        if (($body['id'] ?? null) !== $postId || ! is_string($body['username'] ?? null)
            || ! hash_equals((string) $connection->socialIdentity?->name, $body['username'])) {
            throw new PublishingAdapterException('Threads returned a post that does not match the requested identifier and authenticated user.', 'destination_mismatch');
        }

        return new PublishingAdapterResult(
            externalId: $postId,
            externalUrl: $this->secureUrl($body['permalink'] ?? null),
            status: 'published',
            correlationId: $publication->correlationId,
            idempotencyKey: $publication->idempotencyKey,
            payload: ['network' => 'threads', 'destination_external_id' => $userId, 'post_id' => $postId, 'media_type' => is_string($body['media_type'] ?? null) ? $body['media_type'] : null],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private function validatedMedia(array $media): array
    {
        if (count($media) > 1) {
            throw new PublishingAdapterException('Threads publishing currently supports a single image or video; carousel posts are not enabled.', 'unsupported_media');
        }
        if ($media === []) {
            return [];
        }
        $item = $media[0];
        $mime = $item['mime_type'] ?? null;
        if (! is_string($mime) || ! in_array($mime, ['image/jpeg', 'image/png', 'video/mp4', 'video/quicktime'], true)) {
            throw new PublishingAdapterException('Threads supports JPEG/PNG images and MP4/MOV videos for this adapter.', 'unsupported_media');
        }
        $size = $item['size'] ?? null;
        $limit = str_starts_with($mime, 'image/') ? self::MAX_IMAGE_BYTES : self::MAX_VIDEO_BYTES;
        if (! is_numeric($size) || (int) $size < 1 || (int) $size > $limit) {
            throw new PublishingAdapterException('Threads media size is missing or exceeds the supported limit for the selected media type.', 'media_size_exceeded');
        }
        if (($item['disk'] ?? null) !== 'r2' || ! is_string($item['path'] ?? null) || trim($item['path']) === '') {
            throw new PublishingAdapterException('Threads media must reference an active asset stored in CR8OR R2.', 'media_unavailable');
        }
        $path = trim($item['path']);
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new PublishingAdapterException('The Threads media storage path is invalid.', 'media_unavailable');
        }
        $item['path'] = $path;
        $item['mime_type'] = $mime;
        $item['size'] = (int) $size;
        if (str_starts_with($mime, 'video/')) {
            $metadata = $item['metadata'] ?? [];
            $duration = $item['duration_seconds'] ?? (is_array($metadata) ? ($metadata['duration_seconds'] ?? null) : null);
            if (! is_numeric($duration)) {
                throw new PublishingAdapterException('Threads video duration metadata is required before publishing.', 'media_duration_missing');
            }
            if ((float) $duration > self::MAX_VIDEO_DURATION_SECONDS) {
                throw new PublishingAdapterException('Threads videos cannot exceed five minutes.', 'video_too_long');
            }
        }

        return [$item];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{topic_tag: string|null, reply_control: string|null}
     */
    private function validatedSettings(array $settings): array
    {
        $metadata = $settings['metadata'] ?? [];
        $threads = is_array($metadata) && is_array($metadata['threads'] ?? null) ? $metadata['threads'] : [];
        $topicTag = $threads['topic_tag'] ?? null;
        if ($topicTag !== null && (! is_string($topicTag) || trim($topicTag) === '' || mb_strlen(trim($topicTag)) > 50 || str_starts_with(trim($topicTag), '#'))) {
            throw new PublishingAdapterException('Threads topic tags must be 1 to 50 characters and must not include a leading #.', 'invalid_metadata');
        }
        $replyControl = $threads['reply_control'] ?? null;
        if ($replyControl !== null && (! is_string($replyControl) || ! in_array($replyControl, ['everyone', 'accounts_you_follow', 'mentioned_only'], true))) {
            throw new PublishingAdapterException('Threads reply control must be everyone, accounts_you_follow or mentioned_only.', 'invalid_metadata');
        }

        return ['topic_tag' => is_string($topicTag) ? trim($topicTag) : null, 'reply_control' => $replyControl];
    }

    /** @param array<string, mixed> $media */
    private function altText(array $media): ?string
    {
        $metadata = $media['metadata'] ?? [];
        $value = is_array($metadata) ? ($metadata['alt_text'] ?? null) : null;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > 1000) {
            throw new PublishingAdapterException('Threads media alt text must be at most 1,000 characters.', 'invalid_media_metadata');
        }

        return $value;
    }

    /** @param array<string, mixed> $media */
    private function temporaryMediaUrl(array $media): string
    {
        $path = (string) $media['path'];
        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists($path)) {
                throw new PublishingAdapterException('The selected Threads media no longer exists in R2.', 'media_unavailable');
            }
            $minutes = max(15, min(180, (int) config('publishing.threads.media_url_expiry_minutes', 60)));
            $url = $disk->temporaryUrl($path, CarbonImmutable::now()->addMinutes($minutes));
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('CR8OR could not prepare a temporary R2 URL for Threads media.', 'media_unavailable', retryable: true, previous: $exception);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string(parse_url($url, PHP_URL_HOST))) {
            throw new PublishingAdapterException('R2 did not provide a valid HTTPS URL for Threads media.', 'media_unavailable');
        }

        return $url;
    }

    private function resolveConnection(?int $connectionId, ?string $credentialReference, string $userId): IntegrationConnection
    {
        $connection = $connectionId === null ? null : IntegrationConnection::query()->find($connectionId);
        $identity = $connection?->socialIdentity;
        if ($connection === null || $credentialReference === null || $connection->provider !== 'threads' || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $credentialReference)
            || $identity === null || $identity->provider !== 'threads'
            || (int) $identity->organization_id !== (int) $connection->organization_id
            || ! hash_equals((string) $identity->external_id, (string) $connection->external_account_id)
            || ! hash_equals((string) $connection->external_account_id, $userId)) {
            throw new PublishingAdapterException('The Threads connection, identity or credential reference does not match the requested Threads user.', 'authentication_required');
        }

        return $connection;
    }

    /** @param list<string> $requiredScopes */
    private function accessToken(IntegrationConnection $connection, array $requiredScopes): string
    {
        try {
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('Threads authorization is unavailable and must be renewed.', 'authentication_required', previous: $exception);
        }
        foreach ($requiredScopes as $scope) {
            if (! in_array($scope, $tokens->scopes, true)) {
                throw new PublishingAdapterException('The Threads authorization grant is missing a required publishing or basic-read scope.', 'permission_denied');
            }
        }
        try {
            $token = $this->credentials->resolveAccessToken($connection, 'threads');
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('Threads authorization is unavailable and must be renewed.', 'authentication_required', previous: $exception);
        }
        if (trim($token) === '') {
            throw new PublishingAdapterException('Threads authorization did not provide an access token.', 'authentication_required');
        }

        return $token;
    }

    private function resolveJob(PublishingAdapterRequest $request, IntegrationConnection $connection): PublishingJob
    {
        if ($request->publishingJobId === null) {
            throw new PublishingAdapterException('Threads publishing requires a persisted publishing job for safe retries.', 'publishing_job_required');
        }
        $job = PublishingJob::query()->find($request->publishingJobId);
        if ($job === null || $job->status !== PublishingJob::STATUS_RUNNING
            || ! hash_equals((string) $job->idempotency_key, 'publish-'.$request->idempotencyKey)
            || ($job->network !== null && $job->network !== 'threads')
            || ($job->destination_external_id !== null && ! hash_equals((string) $job->destination_external_id, $request->destinationExternalId))
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $job->enterprise_id)) {
            throw new PublishingAdapterException('The Threads publishing job does not match the connection, destination or publication request.', 'publishing_job_mismatch');
        }

        return $job;
    }

    private function resolveRemoteJob(PublishingRemotePublication $publication, IntegrationConnection $connection): PublishingJob
    {
        if ($publication->publishingJobId === null) {
            throw new PublishingAdapterException('Threads status reconciliation requires a correlated publishing job.', 'publishing_job_required');
        }
        $job = PublishingJob::query()->find($publication->publishingJobId);
        if ($job === null || ! in_array($job->status, [PublishingJob::STATUS_SUCCEEDED, PublishingJob::STATUS_UNKNOWN], true)
            || ! hash_equals((string) $job->idempotency_key, 'publish-'.$publication->idempotencyKey)
            || ($job->network !== null && $job->network !== 'threads')
            || ($job->destination_external_id !== null && ! hash_equals((string) $job->destination_external_id, $publication->destinationExternalId))
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $job->enterprise_id)) {
            throw new PublishingAdapterException('The Threads publishing job does not match the status lookup destination or connection.', 'publishing_job_mismatch');
        }

        return $job;
    }

    private function postPermalink(string $postId, string $token, ?string $username): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->get($this->baseUrl().'/'.rawurlencode($postId), ['fields' => 'id,permalink,username']);
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $body = $response->json();
        if (! is_array($body) || ($body['id'] ?? null) !== $postId
            || ! is_string($body['username'] ?? null) || $username === null || ! hash_equals($username, $body['username'])) {
            return null;
        }

        return $this->secureUrl($body['permalink'] ?? null);
    }

    /** @param array<string, mixed> $state */
    private function persistState(PublishingJob $job, array $state): void
    {
        $job->setAdapterState($state)->save();
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @param  array{topic_tag: string|null, reply_control: string|null}  $settings
     */
    private function requestFingerprint(string $text, array $media, array $settings, string $mediaType): string
    {
        $asset = $media[0] ?? [];

        return hash('sha256', json_encode([
            'text' => $text,
            'media_type' => $mediaType,
            'asset_id' => $asset['asset_id'] ?? null,
            'path' => $asset['path'] ?? null,
            'mime_type' => $asset['mime_type'] ?? null,
            'size' => $asset['size'] ?? null,
            'checksum' => $asset['checksum'] ?? null,
            'settings' => $settings,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $state */
    private function assertMatchingState(array $state, string $userId, string $fingerprint, string $mediaType, bool $allowMissingFingerprint = false): void
    {
        if (($state['destination_external_id'] ?? null) !== $userId
            || ! in_array($state['media_type'] ?? null, ['TEXT', 'IMAGE', 'VIDEO'], true)
            || $state['media_type'] !== $mediaType
            || (! $allowMissingFingerprint && ($state['request_fingerprint'] ?? null) !== $fingerprint)) {
            throw new PublishingAdapterException('The saved Threads container belongs to a different destination or publication request; refusing to reuse it.', 'publishing_state_mismatch');
        }
    }

    private function threadsUserId(string $id): string
    {
        if (preg_match('/^[0-9]{1,64}$/D', $id) !== 1) {
            throw new PublishingAdapterException('Threads destinations must use the canonical numeric Threads user ID, not a username or Meta Page ID.', 'invalid_destination');
        }

        return $id;
    }

    private function baseUrl(): string
    {
        $version = (string) config('publishing.threads.api_version', 'v1.0');
        if (preg_match('/^v[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new PublishingAdapterException('The configured Threads API version must use the format vNN.N.', 'provider_configuration_invalid');
        }

        return 'https://graph.threads.net/'.$version;
    }

    /** @return array<string, mixed> */
    private function assertApiSuccess(Response $response, bool $submission): array
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : null;
        $errorCode = is_array($error) && is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $isTransient = is_array($error) && ($error['is_transient'] ?? false) === true;
        if ($response->successful() && $error === null) {
            if (is_array($body)) {
                return $body;
            }
            throw new PublishingAdapterException('Threads returned an invalid response.', 'invalid_adapter_response', retryable: $submission, ambiguous: $submission);
        }
        if ($response->status() === 401 || $errorCode === 190) {
            throw new PublishingAdapterException('Threads rejected the access token; reconnect the Threads account.', 'authentication_required');
        }
        if ($response->status() === 429 || in_array($errorCode, [4, 17, 32, 613, 80001], true)) {
            throw new PublishingAdapterException('Threads API rate limits were reached; retry after the provider limit resets.', 'rate_limited', retryable: true);
        }
        if ($response->serverError() || $isTransient) {
            throw new PublishingAdapterException('Threads returned a transient provider error.', 'provider_unavailable', retryable: true, ambiguous: $submission);
        }
        if ($response->status() === 403 || in_array($errorCode, [10, 200], true)) {
            throw new PublishingAdapterException('The Threads app or authorization grant lacks permission for this operation; verify app review and granted scopes.', 'permission_denied');
        }

        throw new PublishingAdapterException('Threads rejected the request; verify the destination, media constraints and app eligibility.', 'provider_rejected');
    }

    private function secureUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)
            || ! in_array(strtolower($parts['host']), ['threads.net', 'www.threads.net'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $extra
     */
    private function result(PublishingAdapterRequest|PublishingRemotePublication $request, string $userId, string $externalId, string $status, array $state, array $extra = [], ?string $externalUrl = null): PublishingAdapterResult
    {
        return new PublishingAdapterResult(
            externalId: $externalId,
            externalUrl: $externalUrl,
            status: $status,
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: array_filter([
                'network' => 'threads',
                'destination_external_id' => $userId,
                'container_id' => is_string($state['container_id'] ?? null) ? $state['container_id'] : null,
                'post_id' => ($state['phase'] ?? null) === 'published' && is_string($state['post_id'] ?? null) ? $state['post_id'] : ($status === 'published' ? $externalId : null),
                'media_type' => is_string($state['media_type'] ?? null) ? $state['media_type'] : null,
                ...$extra,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}