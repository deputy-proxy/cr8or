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
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class TikTokPublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_CAPTION_UTF16_UNITS = 2200;

    private const MAX_VIDEO_SIZE_BYTES = 4_294_967_296;

    private const MAX_VIDEO_DURATION_SECONDS = 600;

    private const MIN_VIDEO_DIMENSION = 360;

    private const MAX_VIDEO_DIMENSION = 4096;

    private const MIN_FRAME_RATE = 23.0;

    private const MAX_FRAME_RATE = 60.0;

    private const MIN_CHUNK_SIZE_BYTES = 5_242_880;

    private const MAX_CHUNK_SIZE_BYTES = 67_108_864;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
    ) {}

    public function network(): string
    {
        return 'tiktok';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'tiktok';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'tiktok',
            supportedAccountTypes: ['tiktok.creator'],
            supportedDestinationTypes: ['tiktok.creator'],
            supportedMediaTypes: ['video/mp4', 'video/quicktime', 'video/webm'],
            maxTextLength: self::MAX_CAPTION_UTF16_UNITS,
            maxMediaItems: 1,
            maxMediaSizeBytes: self::MAX_VIDEO_SIZE_BYTES,
            maxVideoDurationSeconds: self::MAX_VIDEO_DURATION_SECONDS,
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported,
            editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported,
            analytics: PublishingCapabilitySupport::Unsupported,
            // CR8OR blocks blind resubmission after ambiguous outcomes.
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The TikTok adapter received a request for another network.', 'network_mismatch');
        }

        $creatorId = $this->creatorId($request->destinationExternalId);
        $media = $this->validatedMedia($request->media);
        if ($this->utf16Length($request->content) > self::MAX_CAPTION_UTF16_UNITS) {
            throw new PublishingAdapterException('TikTok captions cannot exceed 2,200 UTF-16 code units.', 'content_too_long');
        }

        $settings = $this->validatedCreatorSettings($request->settings);
        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference, $creatorId);
        $accessToken = $this->accessToken($connection);
        $creatorInfo = $this->creatorInfo($accessToken);
        $this->validateSettingsAgainstCreator($settings, $creatorInfo);
        $this->validateDurationAgainstCreator($media[0], $creatorInfo);

        $size = (int) $media[0]['size'];
        $chunkSize = $this->chunkSize($size);
        $chunkCount = max(1, (int) floor($size / $chunkSize));

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                    'post_info' => $settings['post_info'] + ['title' => $request->content],
                    'source_info' => [
                        'source' => 'FILE_UPLOAD',
                        'video_size' => $size,
                        'chunk_size' => $chunkSize,
                        'total_chunk_count' => $chunkCount,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'TikTok did not return a definitive response while initializing the video post; the outcome is unknown and must be reconciled before retrying.',
                'timeout',
                retryable: true,
                previous: $exception,
                ambiguous: true,
            );
        }

        $body = $this->assertApiSuccess($response, submission: true);
        $data = $body['data'] ?? null;
        $publishId = is_array($data) ? ($data['publish_id'] ?? null) : null;
        $uploadUrl = is_array($data) ? ($data['upload_url'] ?? null) : null;
        if (! is_string($publishId) || trim($publishId) === '' || mb_strlen($publishId) > 64 || preg_match('/^[A-Za-z0-9_~.-]+$/D', $publishId) !== 1
            || ! is_string($uploadUrl) || ! $this->isTrustedUploadUrl($uploadUrl)) {
            throw new PublishingAdapterException(
                'TikTok initialized the post without returning a valid publish identifier and trusted upload URL; reconcile before retrying.',
                'invalid_adapter_response',
                retryable: true,
                ambiguous: true,
            );
        }

        $this->uploadVideo($media[0], $uploadUrl, $publishId, $accessToken, $chunkSize, $chunkCount);

        return new PublishingAdapterResult(
            externalId: $publishId,
            externalUrl: null,
            status: 'pending',
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: [
                'network' => 'tiktok',
                'destination_external_id' => $creatorId,
                'publish_id' => $publishId,
                'content_type' => 'video',
                'publication_mode' => 'direct_post',
                'status' => 'upload_complete_processing_pending',
            ],
        );
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The TikTok adapter received a status lookup for another network.', 'network_mismatch');
        }

        $creatorId = $this->creatorId($publication->destinationExternalId);
        $publishId = $publication->externalPublicationId;
        if (trim($publishId) === '' || mb_strlen($publishId) > 64 || preg_match('/^[A-Za-z0-9_~.-]+$/D', $publishId) !== 1) {
            throw new PublishingAdapterException('TikTok status lookup requires a valid publish_id.', 'invalid_destination');
        }

        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference, $creatorId);
        $accessToken = $this->accessToken($connection);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', ['publish_id' => $publishId]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('TikTok status lookup did not return a definitive response.', 'timeout', retryable: true, previous: $exception);
        }

        $body = $this->assertApiSuccess($response, submission: false);
        $data = $body['data'] ?? null;
        if (! is_array($data) || ! is_string($data['status'] ?? null)) {
            throw new PublishingAdapterException('TikTok returned an invalid publication status response.', 'invalid_adapter_response');
        }

        $remoteStatus = $data['status'];
        if (! in_array($remoteStatus, ['PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD', 'SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE', 'FAILED'], true)) {
            return null;
        }

        $status = match ($remoteStatus) {
            'PUBLISH_COMPLETE' => 'published',
            'FAILED' => 'failed',
            default => 'pending',
        };

        $publicPostIds = $data['publicaly_available_post_id'] ?? [];
        if (! is_array($publicPostIds)) {
            $publicPostIds = [];
        }
        $publicPostIds = array_values(array_filter($publicPostIds, static fn (mixed $id): bool => is_string($id) || is_int($id)));

        return new PublishingAdapterResult(
            externalId: $publishId,
            externalUrl: null,
            status: $status,
            correlationId: $publication->correlationId,
            idempotencyKey: $publication->idempotencyKey,
            payload: array_filter([
                'network' => 'tiktok',
                'destination_external_id' => $creatorId,
                'publish_id' => $publishId,
                'remote_status' => $remoteStatus,
                'fail_reason' => is_string($data['fail_reason'] ?? null) ? $data['fail_reason'] : null,
                'public_post_ids' => $publicPostIds,
                'uploaded_bytes' => is_numeric($data['uploaded_bytes'] ?? null) ? (int) $data['uploaded_bytes'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{post_info: array<string, mixed>}
     */
    private function validatedCreatorSettings(array $settings): array
    {
        if (($settings['consent_to_publish'] ?? null) !== true) {
            throw new PublishingAdapterException('TikTok direct publishing requires explicit creator consent to send this video to TikTok.', 'creator_consent_required');
        }

        $privacy = $settings['privacy_level'] ?? null;
        if (! is_string($privacy) || trim($privacy) === '') {
            throw new PublishingAdapterException('TikTok direct publishing requires the creator to choose a privacy level.', 'creator_settings_required');
        }

        $postInfo = ['privacy_level' => $privacy];
        foreach (['disable_comment', 'disable_duet', 'disable_stitch', 'brand_content_toggle'] as $key) {
            if (! is_bool($settings[$key] ?? null)) {
                throw new PublishingAdapterException("TikTok direct publishing requires an explicit boolean setting for [{$key}].", 'creator_settings_required');
            }
            $postInfo[$key] = $settings[$key];
        }

        foreach (['brand_organic_toggle', 'is_aigc'] as $key) {
            if (array_key_exists($key, $settings)) {
                if (! is_bool($settings[$key])) {
                    throw new PublishingAdapterException("TikTok creator setting [{$key}] must be boolean when supplied.", 'creator_settings_invalid');
                }
                $postInfo[$key] = $settings[$key];
            }
        }

        if (array_key_exists('video_cover_timestamp_ms', $settings)) {
            $coverTimestamp = $settings['video_cover_timestamp_ms'];
            if (! is_int($coverTimestamp) || $coverTimestamp < 0) {
                throw new PublishingAdapterException('TikTok video cover timestamp must be a non-negative integer in milliseconds.', 'creator_settings_invalid');
            }
            $postInfo['video_cover_timestamp_ms'] = $coverTimestamp;
        }

        return ['post_info' => $postInfo];
    }

    /** @return array<string, mixed> */
    private function creatorInfo(string $accessToken): array
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/');
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('TikTok creator eligibility and settings could not be verified before submission.', 'timeout', retryable: true, previous: $exception);
        }

        $body = $this->assertApiSuccess($response, submission: false);
        $data = $body['data'] ?? null;
        if (! is_array($data)
            || ! is_array($data['privacy_level_options'] ?? null)
            || $data['privacy_level_options'] === []
            || ! is_numeric($data['max_video_post_duration_sec'] ?? null)) {
            throw new PublishingAdapterException('TikTok did not return the creator privacy and video-duration constraints needed to validate this post.', 'invalid_adapter_response');
        }

        return $data;
    }

    /**
     * @param  array{post_info: array<string, mixed>}  $settings
     * @param  array<string, mixed>  $creatorInfo
     */
    private function validateSettingsAgainstCreator(array $settings, array $creatorInfo): void
    {
        $postInfo = $settings['post_info'];
        $privacyOptions = array_values(array_filter($creatorInfo['privacy_level_options'], 'is_string'));
        if (! in_array($postInfo['privacy_level'], $privacyOptions, true)) {
            throw new PublishingAdapterException('The selected TikTok privacy level is not available for this creator account.', 'creator_settings_invalid');
        }

        foreach (['comment' => 'disable_comment', 'duet' => 'disable_duet', 'stitch' => 'disable_stitch'] as $interaction => $setting) {
            $creatorDisabled = $creatorInfo[$interaction.'_disabled'] ?? null;
            if (! is_bool($creatorDisabled)) {
                throw new PublishingAdapterException("TikTok did not return the creator's [{$interaction}] eligibility.", 'invalid_adapter_response');
            }
            if ($creatorDisabled && $postInfo[$setting] !== true) {
                throw new PublishingAdapterException("TikTok creator settings do not permit enabling [{$interaction}] for this post.", 'creator_settings_invalid');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $creatorInfo
     */
    private function validateDurationAgainstCreator(array $media, array $creatorInfo): void
    {
        $maximum = min(self::MAX_VIDEO_DURATION_SECONDS, (int) $creatorInfo['max_video_post_duration_sec']);
        if ((float) $media['duration_seconds'] > $maximum) {
            throw new PublishingAdapterException('The video exceeds this TikTok creator account\'s current maximum post duration.', 'video_too_long');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private function validatedMedia(array $media): array
    {
        if (count($media) !== 1) {
            throw new PublishingAdapterException('TikTok direct publishing currently requires exactly one video.', 'unsupported_media');
        }

        $item = $media[0];
        $mimeType = $item['mime_type'] ?? null;
        if (! is_string($mimeType) || ! in_array($mimeType, ['video/mp4', 'video/quicktime', 'video/webm'], true)) {
            throw new PublishingAdapterException('TikTok video publishing supports MP4, QuickTime/MOV, and WebM containers only.', 'unsupported_media');
        }

        $size = $item['size'] ?? null;
        if (! is_numeric($size) || (int) $size < 1 || (int) $size > self::MAX_VIDEO_SIZE_BYTES) {
            throw new PublishingAdapterException('TikTok video size must be known and no greater than 4 GiB.', 'media_size_exceeded');
        }

        if (($item['disk'] ?? null) !== 'r2' || ! is_string($item['path'] ?? null) || trim($item['path']) === '') {
            throw new PublishingAdapterException('TikTok publishing requires a video stored in CR8OR R2.', 'media_unavailable');
        }

        $path = trim($item['path']);
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new PublishingAdapterException('The TikTok video storage path is invalid.', 'media_unavailable');
        }

        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $dimensions = $item['dimensions'] ?? ($metadata['dimensions'] ?? $metadata);
        $width = is_array($dimensions) ? ($dimensions['width'] ?? null) : null;
        $height = is_array($dimensions) ? ($dimensions['height'] ?? null) : null;
        if (! is_numeric($width) || ! is_numeric($height)
            || (int) $width < self::MIN_VIDEO_DIMENSION || (int) $height < self::MIN_VIDEO_DIMENSION
            || (int) $width > self::MAX_VIDEO_DIMENSION || (int) $height > self::MAX_VIDEO_DIMENSION) {
            throw new PublishingAdapterException('TikTok video dimensions must be known and between 360 and 4096 pixels on each side.', 'invalid_media_dimensions');
        }

        $duration = $item['duration_seconds'] ?? $metadata['duration_seconds'] ?? null;
        if (! is_numeric($duration) || (float) $duration <= 0 || (float) $duration > self::MAX_VIDEO_DURATION_SECONDS) {
            throw new PublishingAdapterException('TikTok video duration must be known, positive, and no longer than 10 minutes.', 'invalid_media_duration');
        }

        $codec = strtolower(trim((string) ($item['codec'] ?? $metadata['codec'] ?? '')));
        $allowedCodecs = match ($mimeType) {
            'video/mp4', 'video/quicktime' => ['h264', 'h.264', 'avc', 'h265', 'h.265', 'hevc'],
            'video/webm' => ['vp8', 'vp9'],
        };
        if (! in_array($codec, $allowedCodecs, true)) {
            throw new PublishingAdapterException('TikTok video codec metadata is missing or incompatible with its container.', 'unsupported_video_codec');
        }

        $frameRate = $item['frame_rate'] ?? $metadata['frame_rate'] ?? null;
        if (! is_numeric($frameRate) || (float) $frameRate < self::MIN_FRAME_RATE || (float) $frameRate > self::MAX_FRAME_RATE) {
            throw new PublishingAdapterException('TikTok video frame rate must be between 23 and 60 FPS.', 'unsupported_frame_rate');
        }

        return [$item + ['duration_seconds' => (float) $duration, 'codec' => $codec, 'frame_rate' => (float) $frameRate]];
    }

    private function resolveConnection(?int $connectionId, ?string $credentialReference, string $creatorId): IntegrationConnection
    {
        if ($connectionId === null || $credentialReference === null) {
            throw new PublishingAdapterException('TikTok publishing requires an authorized creator connection.', 'authentication_required');
        }

        $connection = IntegrationConnection::query()->find($connectionId);
        if ($connection === null
            || $connection->provider !== 'tiktok'
            || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $credentialReference)
            || ! hash_equals((string) $connection->external_account_id, $creatorId)) {
            throw new PublishingAdapterException('The TikTok connection is unavailable or does not match the requested creator and credential reference.', 'authentication_required');
        }

        return $connection;
    }

    private function accessToken(IntegrationConnection $connection): string
    {
        try {
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
            $accessToken = $this->credentials->resolveAccessToken($connection, 'tiktok');
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('TikTok authorization is unavailable and must be renewed before publishing.', 'authentication_required', previous: $exception);
        }

        if (! in_array('video.publish', $tokens->scopes, true)) {
            throw new PublishingAdapterException('The TikTok authorization grant lacks the required [video.publish] scope; authorize the connection again.', 'permission_denied');
        }

        if (trim($accessToken) === '') {
            throw new PublishingAdapterException('TikTok authorization did not provide an access token.', 'authentication_required');
        }

        return $accessToken;
    }

    private function creatorId(string $destinationExternalId): string
    {
        if (trim($destinationExternalId) === '' || mb_strlen($destinationExternalId) > 128 || preg_match('/^[A-Za-z0-9_-]+$/D', $destinationExternalId) !== 1) {
            throw new PublishingAdapterException('TikTok destination must be a canonical creator open_id.', 'invalid_destination');
        }

        return $destinationExternalId;
    }

    /** @return array<string, mixed> */
    private function assertApiSuccess(Response $response, bool $submission, bool $ambiguous = false): array
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $errorCode = $error['code'] ?? null;

        if ($response->status() === 401 || $errorCode === 'access_token_invalid') {
            throw new PublishingAdapterException('TikTok rejected the access token; reconnect the creator account.', 'authentication_required');
        }

        if ($response->status() === 403 && ! in_array($errorCode, ['scope_not_authorized', 'unaudited_client_can_only_post_to_private_accounts'], true)) {
            throw new PublishingAdapterException('TikTok rejected the requested publishing capability or app approval.', 'permission_denied');
        }

        if ($response->status() === 429 || $errorCode === 'rate_limit_exceeded') {
            throw new PublishingAdapterException('TikTok rate limit reached; retry the same publication later.', 'rate_limited', retryable: true);
        }

        if ($response->serverError()) {
            throw new PublishingAdapterException(
                'TikTok returned a server error; the remote operation may have been accepted and must be reconciled before retrying.',
                'provider_error',
                retryable: true,
                ambiguous: $submission || $ambiguous,
            );
        }

        if (! $response->successful() || ! is_array($body) || $errorCode !== 'ok') {
            $message = match ($errorCode) {
                'scope_not_authorized' => 'TikTok authorization is missing the required publishing scope.',
                'privacy_level_option_mismatch' => 'The selected privacy level is not available for this TikTok creator.',
                'unaudited_client_can_only_post_to_private_accounts' => 'TikTok app audit is required for the requested public-posting capability.',
                'spam_risk_too_many_posts', 'reached_active_user_cap' => 'TikTok publishing quota has been reached.',
                'spam_risk_user_banned_from_posting' => 'TikTok has restricted this creator from publishing through the API.',
                'file_format_check_failed' => 'TikTok rejected the video container or codec.',
                'duration_check_failed' => 'TikTok rejected the video duration.',
                'frame_rate_check_failed' => 'TikTok rejected the video frame rate.',
                'picture_size_check_failed' => 'TikTok rejected the video dimensions.',
                'spam_risk_text' => 'TikTok rejected the caption under its content safety rules.',
                default => 'TikTok rejected the publishing request.',
            };
            $failureCode = match ($errorCode) {
                'scope_not_authorized' => 'permission_denied',
                'unaudited_client_can_only_post_to_private_accounts' => 'app_review_required',
                'spam_risk_user_banned_from_posting' => 'creator_restricted',
                'spam_risk_too_many_posts', 'reached_active_user_cap' => 'quota_exceeded',
                'privacy_level_option_mismatch' => 'creator_settings_invalid',
                'file_format_check_failed', 'duration_check_failed', 'frame_rate_check_failed', 'picture_size_check_failed' => 'invalid_media',
                'spam_risk_text' => 'content_rejected',
                default => 'provider_rejected',
            };
            throw new PublishingAdapterException($message, $failureCode);
        }

        return $body;
    }

    /** @param array<string, mixed> $media */
    private function uploadVideo(array $media, string $uploadUrl, string $publishId, string $accessToken, int $chunkSize, int $chunkCount): void
    {
        $stream = null;
        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists((string) $media['path'])) {
                throw new PublishingAdapterException('The selected TikTok video no longer exists in R2 after TikTok initialized the post; reconcile the existing publish_id before retrying.', 'media_unavailable', ambiguous: true);
            }
            $stream = $disk->readStream((string) $media['path']);
            if (! is_resource($stream)) {
                throw new PublishingAdapterException('CR8OR could not open the TikTok video from R2 after TikTok initialized the post; reconcile the existing publish_id before retrying.', 'media_unavailable', retryable: true, ambiguous: true);
            }

            $size = (int) $media['size'];
            $offset = 0;
            for ($index = 0; $index < $chunkCount; $index++) {
                $bytesToRead = min($chunkSize, $size - $offset);
                $chunk = $this->readChunk($stream, $bytesToRead);
                $chunkLength = strlen($chunk);
                if ($chunkLength !== $bytesToRead) {
                    throw new PublishingAdapterException('The TikTok video stream ended before the declared file size.', 'media_unavailable', ambiguous: true);
                }

                $end = $offset + $chunkLength - 1;
                $expectedStatus = $index === $chunkCount - 1 ? 201 : 206;
                $response = $this->sendUploadChunk($uploadUrl, (string) $media['mime_type'], $chunk, $offset, $end, $size);
                if ($response?->status() === $expectedStatus) {
                    $offset += $chunkLength;

                    continue;
                }

                // A timeout or 5xx may happen after TikTok accepted the bytes. Check its durable
                // upload cursor before deciding whether this exact chunk is safe to retry.
                $uploadedBytes = $this->fetchUploadedBytes($accessToken, $publishId);
                if ($uploadedBytes === $end + 1) {
                    $offset += $chunkLength;

                    continue;
                }

                $canRetrySameChunk = $uploadedBytes === $offset
                    && ($response === null || $response->serverError() || $response->status() === 429);
                if ($canRetrySameChunk) {
                    $retryResponse = $this->sendUploadChunk($uploadUrl, (string) $media['mime_type'], $chunk, $offset, $end, $size);
                    if ($retryResponse?->status() === $expectedStatus) {
                        $offset += $chunkLength;

                        continue;
                    }

                    if ($this->fetchUploadedBytes($accessToken, $publishId) === $end + 1) {
                        $offset += $chunkLength;

                        continue;
                    }
                }

                $failureCode = $response?->status() === 429 ? 'rate_limited' : 'upload_incomplete';
                throw new PublishingAdapterException(
                    'TikTok did not confirm video upload chunk '.($index + 1)." of {$chunkCount}; reconcile publish status before retrying.",
                    $failureCode,
                    retryable: $response === null || $response->status() === 429 || $response->serverError(),
                    ambiguous: true,
                );
            }
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('CR8OR could not complete the TikTok video upload; reconcile the existing publish_id before retrying.', 'upload_incomplete', retryable: true, previous: $exception, ambiguous: true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function sendUploadChunk(string $uploadUrl, string $mimeType, string $chunk, int $offset, int $end, int $size): ?Response
    {
        try {
            return Http::withOptions(['allow_redirects' => false])
                ->timeout((int) config('publishing.tiktok.upload_timeout', 120))
                ->withHeaders([
                    'Content-Type' => $mimeType,
                    'Content-Length' => (string) strlen($chunk),
                    'Content-Range' => "bytes {$offset}-{$end}/{$size}",
                ])
                ->withBody($chunk, $mimeType)
                ->put($uploadUrl);
        } catch (ConnectionException) {
            return null;
        }
    }

    private function fetchUploadedBytes(string $accessToken, string $publishId): ?int
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', ['publish_id' => $publishId]);
            $body = $this->assertApiSuccess($response, submission: false);
            $data = $body['data'] ?? null;
            if (! is_array($data) || ! is_numeric($data['uploaded_bytes'] ?? null)) {
                return null;
            }

            return max(0, (int) $data['uploaded_bytes']);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param resource $stream */
    private function readChunk($stream, int $length): string
    {
        $chunk = '';
        while (strlen($chunk) < $length && ! feof($stream)) {
            $remaining = $length - strlen($chunk);
            if ($remaining <= 0) {
                break;
            }
            $part = fread($stream, min(1_048_576, $remaining));
            if ($part === false || $part === '') {
                break;
            }
            $chunk .= $part;
        }

        return $chunk;
    }

    private function chunkSize(int $size): int
    {
        if ($size < self::MIN_CHUNK_SIZE_BYTES) {
            return $size;
        }

        $count = (int) ceil($size / self::MAX_CHUNK_SIZE_BYTES);

        return (int) floor($size / max(1, $count));
    }

    private function isTrustedUploadUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null
            || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'open-upload.tiktokapis.com'
            || (str_starts_with($host, 'upload.') && str_ends_with($host, '.tiktokapis.com'));
    }

    private function utf16Length(string $value): int
    {
        return (int) (strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2);
    }
}