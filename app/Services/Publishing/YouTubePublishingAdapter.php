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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class YouTubePublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_VIDEO_BYTES = 256_000_000_000;

    private const CHUNK_BYTES = 8_388_608;

    public function __construct(private readonly CredentialResolver $credentials, private readonly SocialCredentialStore $credentialStore) {}

    public function network(): string
    {
        return 'youtube';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'youtube';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['publication.publish', 'publication.status.lookup'], true);
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'youtube', supportedAccountTypes: ['youtube.channel'], supportedDestinationTypes: ['youtube.channel'],
            supportedMediaTypes: ['video/mp4', 'video/quicktime', 'video/webm', 'video/mpeg', 'video/3gpp', 'video/x-msvideo'],
            maxTextLength: 5000, maxMediaItems: 1, maxMediaSizeBytes: $this->maxVideoBytes(), maxVideoDurationSeconds: 43_200,
            immediatePublishing: PublishingCapabilitySupport::Supported, nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported, editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported, analytics: PublishingCapabilitySupport::Unsupported,
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== 'youtube') {
            throw new PublishingAdapterException('YouTube received a request for another network.', 'network_mismatch');
        }
        $channelId = $this->channelId($request->destinationExternalId);
        $media = $this->validatedMedia($request->media);
        $metadata = $this->validatedMetadata($request);
        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference);
        $token = $this->accessToken($connection);
        $this->assertChannelAccess($token, $connection, $channelId, (float) $media['duration_seconds']);
        $job = $this->resolveJob($request);
        $checksum = $this->checksum($media);
        $metadataHash = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $state = $job->adapterState();
        $this->assertStateMatches($state, $channelId, $media, $checksum, $metadataHash);
        if (($state['upload_complete'] ?? false) === true && is_string($state['video_id'] ?? null)) {
            return $this->result($request, $channelId, $state['video_id'], $metadata['privacy_status'], 'pending');
        }
        if (! is_string($state['session_uri'] ?? null)) {
            $state = ['session_uri' => $this->startSession($token, $media, $metadata), 'channel_id' => $channelId,
                'mime_type' => $media['mime_type'], 'size' => $media['size'], 'checksum' => $checksum,
                'metadata_fingerprint' => $metadataHash, 'requested_privacy_status' => $metadata['privacy_status'],
                'offset' => 0, 'upload_complete' => false];
            $job->setAdapterState($state)->save();
        }
        $video = $this->upload($job, $state, $media, $token);
        $videoId = $this->videoId($video['id'] ?? null);
        $this->assertVideoChannel($video, $channelId);
        $actualPrivacy = data_get($video, 'status.privacyStatus');
        $job->setAdapterState(['upload_complete' => true, 'video_id' => $videoId, 'channel_id' => $channelId,
            'mime_type' => $media['mime_type'], 'size' => $media['size'], 'checksum' => $checksum,
            'metadata_fingerprint' => $metadataHash, 'requested_privacy_status' => $metadata['privacy_status'],
            'actual_privacy_status' => is_string($actualPrivacy) ? $actualPrivacy : null])->save();

        return $this->result($request, $channelId, $videoId, $metadata['privacy_status'], 'pending', [
            'upload_status' => data_get($video, 'status.uploadStatus', 'uploaded'),
            'processing_status' => data_get($video, 'processingDetails.processingStatus', 'processing'),
            'actual_privacy_status' => $actualPrivacy,
        ]);
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== 'youtube') {
            throw new PublishingAdapterException('YouTube received a status lookup for another network.', 'network_mismatch');
        }
        $channelId = $this->channelId($publication->destinationExternalId);
        $videoId = $this->videoId($publication->externalPublicationId);
        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference);
        $token = $this->accessToken($connection);
        $this->assertChannelAccess($token, $connection, $channelId);
        try {
            $response = Http::acceptJson()->timeout((int) config('social_authorization.timeout', 15))->withToken($token)
                ->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'snippet,status,processingDetails', 'id' => $videoId]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('YouTube processing status could not be retrieved.', 'timeout', retryable: true, previous: $exception);
        }
        $body = $this->apiBody($response);
        $items = $body['items'] ?? null;
        if (! is_array($items) || ! isset($items[0]) || ! is_array($items[0])) {
            return null;
        }
        $video = $items[0];
        if (($video['id'] ?? null) !== $videoId || data_get($video, 'snippet.channelId') !== $channelId) {
            throw new PublishingAdapterException('The returned YouTube video does not belong to the requested channel.', 'destination_mismatch');
        }
        $upload = data_get($video, 'status.uploadStatus');
        $processing = data_get($video, 'processingDetails.processingStatus');
        $actualPrivacy = data_get($video, 'status.privacyStatus');
        $requestedPrivacy = $publication->metadata['requested_privacy_status'] ?? null;
        if (! in_array($requestedPrivacy, ['private', 'unlisted', 'public'], true)) {
            $requestedPrivacy = null;
        }
        $privacyMismatch = is_string($actualPrivacy) && $requestedPrivacy !== null && $requestedPrivacy !== $actualPrivacy;
        if (in_array($upload, ['failed', 'rejected', 'deleted'], true) || in_array($processing, ['failed', 'terminated'], true)) {
            $failureReason = data_get($video, 'processingDetails.processingFailureReason') ?? data_get($video, 'status.rejectionReason');

            return $this->result($publication, $channelId, $videoId, is_string($requestedPrivacy) ? $requestedPrivacy : 'private', 'failed', ['failure_code' => 'processing_failed', 'upload_status' => $upload, 'processing_status' => $processing, 'failure_reason' => is_string($failureReason) ? $failureReason : null]);
        }
        if ($processing === 'processing' || $upload === 'uploaded' || ! is_string($actualPrivacy) || (! is_string($processing) && $upload !== 'processed')) {
            return $this->result($publication, $channelId, $videoId, is_string($requestedPrivacy) ? $requestedPrivacy : 'private', 'pending', ['upload_status' => $upload, 'processing_status' => $processing, 'actual_privacy_status' => $actualPrivacy, 'privacy_mismatch' => $privacyMismatch]);
        }
        if ($privacyMismatch) {
            return $this->result($publication, $channelId, $videoId, (string) $requestedPrivacy, 'failed', ['failure_code' => 'privacy_restriction', 'actual_privacy_status' => $actualPrivacy, 'failure_reason' => 'YouTube did not apply the requested visibility. Verify API project and channel restrictions.']);
        }
        if ($processing === 'succeeded' || $upload === 'processed') {
            return $this->result($publication, $channelId, $videoId, $requestedPrivacy ?? $actualPrivacy, 'published', ['upload_status' => $upload, 'processing_status' => $processing, 'actual_privacy_status' => $actualPrivacy]);
        }

        return $this->result($publication, $channelId, $videoId, is_string($requestedPrivacy) ? $requestedPrivacy : 'private', 'pending', ['upload_status' => $upload, 'processing_status' => $processing]);
    }

    /** @return array<string, mixed> */
    private function validatedMetadata(PublishingAdapterRequest $request): array
    {
        $rawMetadata = $request->settings['metadata'] ?? [];
        if (! is_array($rawMetadata)) {
            throw new PublishingAdapterException('YouTube publishing metadata must be a structured object.', 'invalid_metadata');
        }
        $settings = $rawMetadata['youtube'] ?? [];
        if (! is_array($settings)) {
            throw new PublishingAdapterException('YouTube-specific metadata must be a structured object.', 'invalid_metadata');
        }
        $title = $settings['title'] ?? $request->settings['title'] ?? '';
        $description = $settings['description'] ?? $request->content;
        if (! is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 100 || str_contains($title, '<') || str_contains($title, '>')) {
            throw new PublishingAdapterException('YouTube title must be non-empty and no longer than 100 characters.', 'invalid_metadata');
        }
        if (! is_string($description) || mb_strlen($description) > 5000 || ! mb_check_encoding($description, 'UTF-8')) {
            throw new PublishingAdapterException('YouTube description cannot exceed 5,000 characters.', 'invalid_metadata');
        }
        $tags = $settings['tags'] ?? [];
        if (! is_array($tags) || ! array_is_list($tags)) {
            throw new PublishingAdapterException('YouTube tags must be a list of strings.', 'invalid_metadata');
        }
        $normalized = [];
        $total = 0;
        foreach ($tags as $tag) {
            if (! is_string($tag) || trim($tag) === '' || mb_strlen(trim($tag)) > 30 || ! mb_check_encoding($tag, 'UTF-8')) {
                throw new PublishingAdapterException('Each YouTube tag must be a non-empty UTF-8 string.', 'invalid_metadata');
            }
            $tag = trim($tag);
            $total += mb_strlen($tag) + (count($normalized) > 0 ? 1 : 0);
            $normalized[] = $tag;
        }
        if ($total > 500) {
            throw new PublishingAdapterException('Combined YouTube tag text cannot exceed 500 characters.', 'invalid_metadata');
        }
        $category = $settings['category_id'] ?? config('publishing.youtube.default_category_id');
        if ($category !== null && ((! is_string($category) && ! is_int($category)) || preg_match('/^[1-9][0-9]{0,4}$/D', (string) $category) !== 1)) {
            throw new PublishingAdapterException('YouTube category ID must be a positive numeric identifier.', 'invalid_metadata');
        }
        $privacy = $settings['privacy_status'] ?? config('publishing.youtube.default_privacy_status', 'private');
        if (! is_string($privacy) || ! in_array($privacy, ['private', 'unlisted', 'public'], true)) {
            throw new PublishingAdapterException('YouTube privacy status must be private, unlisted or public.', 'invalid_metadata');
        }
        $madeForKids = $this->optionalBoolean($settings['made_for_kids'] ?? null, 'made-for-kids');
        $synthetic = $this->optionalBoolean($settings['contains_synthetic_media'] ?? null, 'synthetic-media');
        $notifySubscribers = $this->optionalBoolean($settings['notify_subscribers'] ?? config('publishing.youtube.notify_subscribers', false), 'subscriber notification') ?? false;
        $license = $settings['license'] ?? null;
        if ($license !== null && (! is_string($license) || ! in_array($license, ['youtube', 'creativeCommon'], true))) {
            throw new PublishingAdapterException('YouTube license must be youtube or creativeCommon.', 'invalid_metadata');
        }
        $metadata = ['title' => trim($title), 'description' => $description, 'tags' => array_values(array_unique($normalized)), 'privacy_status' => $privacy, 'notify_subscribers' => $notifySubscribers];
        if ($category !== null) {
            $metadata['category_id'] = (string) $category;
        }
        if (is_bool($madeForKids)) {
            $metadata['made_for_kids'] = $madeForKids;
        }
        if (is_bool($synthetic)) {
            $metadata['contains_synthetic_media'] = $synthetic;
        }
        if (is_string($license)) {
            $metadata['license'] = $license;
        }

        return $metadata;
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return array<string, mixed>
     */
    private function validatedMedia(array $media): array
    {
        if (count($media) !== 1) {
            throw new PublishingAdapterException('YouTube publishing requires exactly one video asset.', 'unsupported_media');
        }
        $item = $media[0];
        $mime = $item['mime_type'] ?? null;
        if (! is_string($mime) || ! in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm', 'video/mpeg', 'video/3gpp', 'video/x-msvideo'], true)) {
            throw new PublishingAdapterException('Unsupported video MIME type: YouTube supports MP4, MOV, WebM, MPEG, 3GPP and AVI video assets.', 'unsupported_media');
        }
        $size = $item['size'] ?? null;
        if (! is_numeric($size) || (int) $size < 1 || (int) $size > $this->maxVideoBytes()) {
            throw new PublishingAdapterException('YouTube video size must be known and no greater than 256 GiB.', 'media_size_exceeded');
        }
        $duration = $item['duration_seconds'] ?? data_get($item, 'metadata.duration_seconds');
        if (! is_numeric($duration) || (float) $duration <= 0) {
            throw new PublishingAdapterException('YouTube video duration metadata is required.', 'media_duration_missing');
        }
        if ((float) $duration > 43_200) {
            throw new PublishingAdapterException('YouTube videos cannot exceed 12 hours.', 'video_too_long');
        }
        $path = $item['path'] ?? null;
        if (($item['disk'] ?? null) !== 'r2' || ! is_string($path) || trim($path) === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new PublishingAdapterException('YouTube video must reference a valid R2 asset path.', 'media_unavailable');
        }

        return $item + ['path' => trim($path), 'mime_type' => $mime, 'size' => (int) $size, 'duration_seconds' => (float) $duration];
    }

    private function resolveConnection(?int $id, ?string $reference): IntegrationConnection
    {
        $connection = $id === null ? null : IntegrationConnection::query()->find($id);
        $identity = $connection?->socialIdentity;
        if ($connection === null || $reference === null || $connection->provider !== 'youtube' || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $reference) || $identity === null || $identity->provider !== 'youtube'
            || (int) $identity->organization_id !== (int) $connection->organization_id || ! hash_equals((string) $identity->external_id, (string) $connection->external_account_id)) {
            throw new PublishingAdapterException('The Google connection or its credential reference is invalid.', 'authentication_required');
        }

        return $connection;
    }

    private function accessToken(IntegrationConnection $connection): string
    {
        try {
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('YouTube authorization must be renewed before publishing.', 'authentication_required', previous: $exception);
        }
        foreach (['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'] as $scope) {
            if (! in_array($scope, $tokens->scopes, true)) {
                throw new PublishingAdapterException('Google authorization is missing a required YouTube upload or read-only scope.', 'permission_denied');
            }
        }
        try {
            $token = $this->credentials->resolveAccessToken($connection, 'youtube');
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('YouTube authorization must be renewed before publishing.', 'authentication_required', previous: $exception);
        }
        if (trim($token) === '') {
            throw new PublishingAdapterException('Google did not provide an access token.', 'authentication_required');
        }

        return $token;
    }

    private function assertChannelAccess(string $token, IntegrationConnection $connection, string $destination, ?float $durationSeconds = null): void
    {
        if (! hash_equals((string) $connection->external_account_id, $destination)) {
            throw new PublishingAdapterException('YouTube upload APIs target the authenticated channel; another channel cannot be selected by video ID.', 'destination_mismatch');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'id,status',
                    'mine' => 'true',
                    'maxResults' => 2,
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Google channel verification did not return a definitive response.', 'provider_unavailable', retryable: true, previous: $exception);
        }

        $body = $this->apiBody($response);
        $items = $body['items'] ?? null;
        if (! is_array($items)) {
            throw new PublishingAdapterException('Google returned an invalid channel list.', 'invalid_adapter_response');
        }
        if (count($items) > 1 || (is_string($body['nextPageToken'] ?? null) && $body['nextPageToken'] !== '')) {
            throw new PublishingAdapterException('This Google identity exposes multiple YouTube channels; authorize a single channel identity before publishing because videos.insert cannot target an arbitrary channel ID.', 'destination_ambiguous');
        }

        $channel = $items[0] ?? null;
        $channelId = is_array($channel) ? ($channel['id'] ?? null) : null;
        if (! is_string($channelId) || ! hash_equals((string) $connection->external_account_id, $channelId)) {
            throw new PublishingAdapterException('The requested YouTube channel does not match the authenticated channel identity.', 'destination_mismatch');
        }

        if ($durationSeconds !== null && $durationSeconds > 900 && data_get($channel, 'status.longUploadsStatus') !== 'allowed') {
            throw new PublishingAdapterException('This YouTube channel must enable long uploads before videos longer than 15 minutes can be submitted.', 'long_uploads_not_enabled');
        }
    }

    private function resolveJob(PublishingAdapterRequest $request): PublishingJob
    {
        if ($request->publishingJobId === null) {
            throw new PublishingAdapterException('YouTube resumable upload requires a persisted publishing job.', 'publishing_job_required');
        }
        $job = PublishingJob::query()->find($request->publishingJobId);
        if ($job === null || $job->status !== PublishingJob::STATUS_RUNNING || $job->adapter_key !== 'youtube' || ! hash_equals((string) $job->idempotency_key, 'publish-'.$request->idempotencyKey)
            || ($job->network !== null && $job->network !== 'youtube') || ($job->destination_external_id !== null && ! hash_equals((string) $job->destination_external_id, $request->destinationExternalId))) {
            throw new PublishingAdapterException('The YouTube publishing job does not match this publication request.', 'publishing_job_mismatch');
        }

        return $job;
    }

    /** @param array<string, mixed> $media */
    private function checksum(array $media): string
    {
        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists((string) $media['path'])) {
                throw new PublishingAdapterException('The YouTube video no longer exists in R2.', 'media_unavailable');
            }
            $stream = $disk->readStream((string) $media['path']);
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('The YouTube video could not be opened in R2.', 'media_unavailable', retryable: true, previous: $exception);
        }
        if (! is_resource($stream)) {
            throw new PublishingAdapterException('The YouTube video stream could not be opened.', 'media_unavailable', retryable: true);
        }
        try {
            $header = fread($stream, 16);
            if (! is_string($header) || ! $this->validVideoHeader((string) $media['mime_type'], $header)) {
                throw new PublishingAdapterException('The YouTube video bytes do not match the declared video container format.', 'invalid_media');
            }
            rewind($stream);
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);
            if ($bytes !== (int) $media['size']) {
                throw new PublishingAdapterException('The YouTube stream size does not match its registered asset version.', 'media_unavailable');
            }

            $checksum = hash_final($context);
            $registeredChecksum = $media['checksum'] ?? null;
            if (is_string($registeredChecksum) && preg_match('/^[a-f0-9]{64}$/iD', $registeredChecksum) === 1 && ! hash_equals(strtolower($registeredChecksum), $checksum)) {
                throw new PublishingAdapterException('The YouTube video checksum does not match the registered asset version.', 'media_checksum_mismatch');
            }

            return $checksum;
        } finally {
            fclose($stream);
        }
    }

    private function validVideoHeader(string $mime, string $header): bool
    {
        return match ($mime) {
            'video/mp4', 'video/quicktime', 'video/3gpp' => strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp',
            'video/webm' => str_starts_with($header, "\x1A\x45\xDF\xA3"),
            'video/mpeg' => str_starts_with($header, "\x00\x00\x01\xBA") || str_starts_with($header, "\x00\x00\x01\xB3"),
            'video/x-msvideo' => strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'AVI ',
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $metadata
     */
    private function startSession(string $token, array $media, array $metadata): string
    {
        $snippet = ['title' => $metadata['title'], 'description' => $metadata['description'], 'tags' => $metadata['tags']];
        if (isset($metadata['category_id'])) {
            $snippet['categoryId'] = $metadata['category_id'];
        }
        $status = ['privacyStatus' => $metadata['privacy_status']];
        if (isset($metadata['made_for_kids'])) {
            $status['selfDeclaredMadeForKids'] = $metadata['made_for_kids'];
        }
        if (isset($metadata['contains_synthetic_media'])) {
            $status['containsSyntheticMedia'] = $metadata['contains_synthetic_media'];
        }
        if (isset($metadata['license'])) {
            $status['license'] = $metadata['license'];
        }
        try {
            $response = Http::acceptJson()->asJson()->timeout((int) config('publishing.youtube.upload_timeout', 120))->withToken($token)
                ->withHeaders(['X-Upload-Content-Length' => (string) $media['size'], 'X-Upload-Content-Type' => (string) $media['mime_type']])
                ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&notifySubscribers='.(($metadata['notify_subscribers'] ?? false) ? 'true' : 'false'), ['snippet' => $snippet, 'status' => $status]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('YouTube did not return a resumable upload session; no video bytes were submitted.', 'timeout', retryable: true, previous: $exception);
        }
        if (! $response->successful()) {
            $this->apiBody($response);
        }
        $location = $response->header('Location');
        if (trim($location) === '' || ! $this->trustedSessionUri($location)) {
            throw new PublishingAdapterException('Google did not return a valid YouTube resumable upload session URI.', 'invalid_adapter_response');
        }

        return $location;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    private function upload(PublishingJob $job, array $state, array $media, string $token): array
    {
        $session = (string) $state['session_uri'];
        $size = (int) $media['size'];
        $stream = null;
        try {
            $stream = Storage::disk('r2')->readStream((string) $media['path']);
            if (! is_resource($stream)) {
                throw new PublishingAdapterException('The YouTube video stream could not be opened from R2.', 'media_unavailable', retryable: true);
            }
            $status = $this->querySession($session, $token, $size);
            if (isset($status['video'])) {
                return $status['video'];
            }
            $offset = (int) $status['offset'];
            $state['offset'] = $offset;
            $job->setAdapterState($state)->save();
            $this->seek($stream, $offset);
            $noProgress = 0;

            while ($offset < $size) {
                $length = min($this->chunkSize(), $size - $offset);
                $chunk = $this->readChunk($stream, $length);
                if (strlen($chunk) !== $length) {
                    throw new PublishingAdapterException('The YouTube stream ended before the registered asset size.', 'media_unavailable', retryable: true);
                }
                $response = $this->sendChunk($session, (string) $media['mime_type'], $chunk, $offset, $size, $token);
                if ($response !== null && in_array($response->status(), [200, 201], true)) {
                    $video = $this->videoBody($response);
                    if ($video !== null) {
                        return $video;
                    }
                    throw new PublishingAdapterException('YouTube completed an upload without a video ID; retry will reconcile the saved session.', 'invalid_adapter_response', retryable: true);
                }
                if ($response !== null && ! in_array($response->status(), [308, 404, 410, 429], true) && ! $response->serverError()) {
                    $this->apiBody($response, ambiguous: true);
                }
                if ($response?->status() === 308 && trim($response->header('Range')) !== '') {
                    $range = trim($response->header('Range'));
                    if (preg_match('/^bytes=0-(\d+)$/D', $range, $matches) !== 1) {
                        throw new PublishingAdapterException('YouTube returned an invalid resumable upload range.', 'invalid_adapter_response', retryable: true);
                    }
                    $next = (int) $matches[1] + 1;
                    if ($next > $size) {
                        throw new PublishingAdapterException('YouTube returned a range beyond the asset size.', 'invalid_adapter_response', retryable: true);
                    }
                } else {
                    $status = $this->querySession($session, $token, $size);
                    if (isset($status['video'])) {
                        return $status['video'];
                    }
                    $next = (int) $status['offset'];
                }
                if ($next < $offset || $next > $size) {
                    throw new PublishingAdapterException('YouTube returned an invalid resumable upload offset.', 'invalid_adapter_response', retryable: true);
                }
                if ($next === $offset) {
                    $noProgress++;
                    if ($noProgress >= 2) {
                        throw new PublishingAdapterException('YouTube did not accept the next chunk; the encrypted upload session is retained for safe retry.', $response?->status() === 429 ? 'rate_limited' : 'upload_incomplete', retryable: true);
                    }
                    $this->seek($stream, $offset);

                    continue;
                }
                $noProgress = 0;
                $offset = $next;
                $state['offset'] = $offset;
                $job->setAdapterState($state)->save();
                $this->seek($stream, $offset);
            }
            $status = $this->querySession($session, $token, $size);
            if (isset($status['video'])) {
                return $status['video'];
            }
            throw new PublishingAdapterException('YouTube has not confirmed completion of the video upload.', 'upload_incomplete', retryable: true);
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('YouTube upload was interrupted; the encrypted session is retained for safe retry.', 'upload_incomplete', retryable: true, previous: $exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @return array{offset: int, video?: array<string, mixed>} */
    private function querySession(string $session, string $token, int $size): array
    {
        try {
            $response = Http::withOptions(['allow_redirects' => false])->timeout((int) config('publishing.youtube.upload_timeout', 120))->withToken($token)
                ->withHeaders(['Content-Length' => '0', 'Content-Range' => 'bytes */'.$size])->withBody('', 'application/octet-stream')->put($session);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('YouTube upload status could not be confirmed; retry the same session.', 'timeout', retryable: true, previous: $exception);
        }
        if (in_array($response->status(), [200, 201], true)) {
            $video = $this->videoBody($response);
            if ($video === null) {
                throw new PublishingAdapterException('YouTube reported a completed session without a valid video resource.', 'invalid_adapter_response', retryable: true);
            }

            return ['offset' => $size, 'video' => $video];
        }
        if ($response->status() === 308) {
            $range = $response->header('Range');
            if (trim($range) === '') {
                return ['offset' => 0];
            }
            if (preg_match('/^bytes=0-(\d+)$/D', trim($range), $matches) !== 1) {
                throw new PublishingAdapterException('YouTube returned an invalid resumable upload range.', 'invalid_adapter_response', retryable: true);
            }
            $offset = (int) $matches[1] + 1;
            if ($offset > $size) {
                throw new PublishingAdapterException('YouTube returned a range beyond the asset size.', 'invalid_adapter_response', retryable: true);
            }

            return ['offset' => $offset];
        }
        if (in_array($response->status(), [404, 410], true)) {
            throw new PublishingAdapterException('The YouTube resumable session expired before its final video ID was confirmed; automatic restart is blocked to prevent duplicates.', 'upload_session_expired', ambiguous: true);
        }
        $this->apiBody($response);
        throw new PublishingAdapterException('YouTube returned an unexpected resumable upload status.', 'upload_incomplete', retryable: true);
    }

    private function sendChunk(string $session, string $mime, string $chunk, int $offset, int $size, string $token): ?Response
    {
        try {
            return Http::withOptions(['allow_redirects' => false])->timeout((int) config('publishing.youtube.upload_timeout', 120))->withToken($token)
                ->withHeaders(['Content-Type' => $mime, 'Content-Length' => (string) strlen($chunk), 'Content-Range' => 'bytes '.$offset.'-'.($offset + strlen($chunk) - 1).'/'.$size])
                ->withBody($chunk, $mime)->put($session);
        } catch (ConnectionException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function videoBody(Response $response): ?array
    {
        $body = $response->json();
        if (! is_array($body) || ! is_string($body['id'] ?? null)) {
            return null;
        }
        $this->videoId($body['id']);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $media
     */
    private function assertStateMatches(array $state, string $channel, array $media, string $checksum, string $metadataHash): void
    {
        if ($state === []) {
            return;
        }
        if (($state['channel_id'] ?? null) !== $channel || ($state['mime_type'] ?? null) !== $media['mime_type']
            || (int) ($state['size'] ?? 0) !== (int) $media['size'] || ($state['checksum'] ?? null) !== $checksum
            || ($state['metadata_fingerprint'] ?? null) !== $metadataHash) {
            throw new PublishingAdapterException('The saved YouTube session belongs to different media, metadata or destination; refusing to reuse it.', 'upload_state_mismatch');
        }
    }

    private function trustedSessionUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'www.googleapis.com'
            || ($parts['path'] ?? null) !== '/upload/youtube/v3/videos' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);

        return (! isset($query['uploadType']) || $query['uploadType'] === 'resumable') && is_string($query['upload_id'] ?? null) && $query['upload_id'] !== '';
    }

    private function maxVideoBytes(): int
    {
        return max(1, min(self::MAX_VIDEO_BYTES, (int) config('publishing.youtube.max_upload_bytes', self::MAX_VIDEO_BYTES)));
    }

    private function optionalBoolean(mixed $value, string $label): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'true' || $value === '1' || $value === 1) {
            return true;
        }
        if ($value === 'false' || $value === '0' || $value === 0) {
            return false;
        }

        throw new PublishingAdapterException("YouTube {$label} declaration must be a boolean.", 'invalid_metadata');
    }

    /** @param array<string, mixed> $video */
    private function assertVideoChannel(array $video, string $channelId): void
    {
        $actualChannel = data_get($video, 'snippet.channelId');
        if (! is_string($actualChannel) || ! hash_equals($channelId, $actualChannel)) {
            throw new PublishingAdapterException('YouTube accepted the upload without confirming the expected channel; do not retry automatically.', 'destination_mismatch', ambiguous: true);
        }
    }

    private function channelId(string $id): string
    {
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $id) !== 1) {
            throw new PublishingAdapterException('YouTube destination must be a canonical authenticated channel ID.', 'invalid_destination');
        }

        return $id;
    }

    private function videoId(mixed $id): string
    {
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) !== 1) {
            throw new PublishingAdapterException('YouTube returned an invalid video ID.', 'invalid_adapter_response');
        }

        return $id;
    }

    private function chunkSize(): int
    {
        $configured = max(262_144, min(67_108_864, (int) config('publishing.youtube.chunk_size_bytes', self::CHUNK_BYTES)));

        return max(262_144, intdiv($configured, 262_144) * 262_144);
    }

    /** @param resource $stream */
    private function seek($stream, int $offset): void
    {
        if (fseek($stream, $offset) === 0) {
            return;
        }
        rewind($stream);
        $remaining = $offset;
        while ($remaining > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(1_048_576, $remaining));
            if (! is_string($chunk) || $chunk === '') {
                break;
            }
            $remaining -= strlen($chunk);
        }
        if ($remaining !== 0) {
            throw new PublishingAdapterException('The R2 video stream cannot seek to YouTube\'s confirmed upload offset.', 'media_unavailable', retryable: true);
        }
    }

    /** @param resource $stream */
    private function readChunk($stream, int $length): string
    {
        $chunk = '';
        while (strlen($chunk) < $length && ! feof($stream)) {
            $remaining = $length - strlen($chunk);
            if ($remaining < 1) {
                break;
            }
            $part = fread($stream, min(1_048_576, $remaining));
            if (! is_string($part) || $part === '') {
                break;
            }
            $chunk .= $part;
        }

        return $chunk;
    }

    /** @return array<string, mixed> */
    private function apiBody(Response $response, bool $submission = false, bool $ambiguous = false): array
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $errors = is_array($error['errors'] ?? null) ? $error['errors'] : [];
        $reason = isset($errors[0]) && is_array($errors[0]) && is_string($errors[0]['reason'] ?? null) ? $errors[0]['reason'] : null;
        if ($response->status() === 401) {
            throw new PublishingAdapterException('Google rejected the access token; reconnect the YouTube account.', 'authentication_required');
        }
        if ($response->status() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            throw new PublishingAdapterException('YouTube API rate limits were reached.', 'rate_limited', retryable: true, ambiguous: $ambiguous);
        }
        if ($response->status() === 403 && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded'], true)) {
            throw new PublishingAdapterException('YouTube API quota or channel upload limit was reached.', $reason === 'uploadLimitExceeded' ? 'upload_limit_exceeded' : 'quota_exceeded', retryable: $reason !== 'uploadLimitExceeded', ambiguous: $ambiguous);
        }
        if ($response->serverError()) {
            throw new PublishingAdapterException('YouTube returned a server error; reconcile the saved upload session before retrying.', 'provider_unavailable', retryable: true, ambiguous: $submission || $ambiguous);
        }
        if (! $response->successful() || ! is_array($body) || $error !== []) {
            $code = match ($reason) {
                'invalidTitle', 'invalidDescription', 'invalidTags', 'invalidCategoryId', 'invalidVideoMetadata' => 'invalid_metadata',
                'forbiddenPrivacySetting' => 'privacy_restriction',
                'forbidden', 'insufficientPermissions' => 'permission_denied',
                'processingFailure', 'invalidVideo' => 'processing_failed',
                default => 'provider_rejected',
            };
            throw new PublishingAdapterException(match ($code) {
                'invalid_metadata' => 'YouTube rejected the video metadata; correct title, description, tags or category.',
                'privacy_restriction' => 'YouTube does not permit the requested privacy setting for this account or API project.',
                'permission_denied' => 'Google authorization or the YouTube API project lacks permission for this operation.',
                'processing_failed' => 'YouTube rejected the video during upload or processing.',
                default => 'YouTube rejected the publishing request; review the Google Cloud API error details.',
            }, $code, ambiguous: $ambiguous);
        }

        return $body;
    }

    /** @param array<string, mixed> $extra */
    private function result(PublishingAdapterRequest|PublishingRemotePublication $request, string $channelId, string $videoId, string $privacy, string $status, array $extra = []): PublishingAdapterResult
    {
        return new PublishingAdapterResult(
            externalId: $videoId,
            externalUrl: 'https://www.youtube.com/watch?v='.$videoId,
            status: $status,
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: array_filter(['network' => 'youtube', 'destination_external_id' => $channelId, 'video_id' => $videoId, 'requested_privacy_status' => $privacy, ...$extra], static fn (mixed $value): bool => $value !== null),
        );
    }
}