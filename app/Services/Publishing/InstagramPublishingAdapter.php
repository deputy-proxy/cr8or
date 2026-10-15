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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class InstagramPublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_CAPTION_LENGTH = 2200;

    private const MAX_IMAGE_SIZE_BYTES = 8_388_608;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
    ) {}

    public function network(): string
    {
        return 'instagram';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'instagram';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'instagram',
            supportedAccountTypes: ['instagram.professional'],
            supportedDestinationTypes: ['instagram.professional'],
            supportedMediaTypes: ['image/jpeg'],
            maxTextLength: self::MAX_CAPTION_LENGTH,
            maxMediaItems: 1,
            maxMediaSizeBytes: self::MAX_IMAGE_SIZE_BYTES,
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported,
            editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported,
            analytics: PublishingCapabilitySupport::Unsupported,
            // CR8OR blocks blind resubmission when Meta may have accepted a request.
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The Instagram adapter received a request for another network.', 'network_mismatch');
        }

        $instagramUserId = $this->instagramUserId($request->destinationExternalId);
        $media = $this->validatedMedia($request->media);
        $altText = $this->validatedAltText($media[0]);

        if (count($media) !== 1) {
            throw new PublishingAdapterException('Instagram publishing currently requires exactly one JPEG image.', 'unsupported_media');
        }

        if (mb_strlen($request->content) > self::MAX_CAPTION_LENGTH) {
            throw new PublishingAdapterException('Instagram caption exceeds the 2,200-character limit.', 'content_too_long');
        }

        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference);
        $requiredScope = $connection->provider === 'meta' ? 'instagram_content_publish' : 'instagram_business_content_publish';
        $accessToken = $this->instagramAccessToken($connection, $instagramUserId, $requiredScope);
        $version = $this->graphApiVersion($connection->provider);
        $graphHost = $this->graphApiHost($connection->provider);
        $this->assertPublishingQuota($instagramUserId, $accessToken, $version, $graphHost);
        $imageUrl = $this->temporaryMediaUrl($media[0]);

        try {
            $containerResponse = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->post("{$graphHost}/{$version}/{$instagramUserId}/media", array_filter([
                    'image_url' => $imageUrl,
                    'caption' => $request->content,
                    'alt_text' => $altText,
                ], static fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'Instagram did not return a definitive response while creating the Instagram media container.',
                'timeout',
                retryable: true,
                previous: $exception,
                ambiguous: true,
            );
        }

        $containerBody = $this->assertGraphSuccess($containerResponse, submission: false);
        $containerId = $containerBody['id'] ?? null;
        if (! is_string($containerId) || preg_match('/^[0-9]+$/D', $containerId) !== 1) {
            throw new PublishingAdapterException('Instagram did not return a valid Instagram media-container identifier.', 'invalid_adapter_response');
        }

        $this->awaitContainerReady($containerId, $accessToken, $version, $graphHost);

        try {
            $publishResponse = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->post("{$graphHost}/{$version}/{$instagramUserId}/media_publish", [
                    'creation_id' => $containerId,
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'Instagram did not return a definitive response after Instagram media publication; the outcome is unknown and must be reconciled before retrying.',
                'timeout',
                retryable: true,
                previous: $exception,
                ambiguous: true,
            );
        }

        $body = $this->assertGraphSuccess($publishResponse, submission: true);
        $mediaId = $body['id'] ?? null;
        if (! is_string($mediaId) || preg_match('/^[0-9]+$/D', $mediaId) !== 1) {
            throw new PublishingAdapterException(
                'Instagram accepted Instagram publication without returning a valid media identifier; the outcome is unknown and must be reconciled before retrying.',
                'invalid_adapter_response',
                retryable: true,
                ambiguous: true,
            );
        }

        return new PublishingAdapterResult(
            externalId: $mediaId,
            externalUrl: null,
            status: 'published',
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: [
                'network' => 'instagram',
                'destination_external_id' => $instagramUserId,
                'content_type' => 'image',
                'media_count' => 1,
                'container_id' => $containerId,
            ],
        );
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The Instagram adapter received a status lookup for another network.', 'network_mismatch');
        }

        $instagramUserId = $this->instagramUserId($publication->destinationExternalId);
        if (preg_match('/^[0-9]+$/D', $publication->externalPublicationId) !== 1) {
            throw new PublishingAdapterException('Instagram status lookup requires a numeric media ID.', 'invalid_destination');
        }

        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference);
        $requiredScope = $connection->provider === 'meta' ? 'instagram_basic' : 'instagram_business_basic';
        $accessToken = $this->instagramAccessToken($connection, $instagramUserId, $requiredScope);
        $version = $this->graphApiVersion($connection->provider);
        $graphHost = $this->graphApiHost($connection->provider);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->get("{$graphHost}/{$version}/".rawurlencode($publication->externalPublicationId), [
                    'fields' => 'id,media_type,permalink,timestamp',
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'Instagram status lookup did not return a definitive response.',
                'timeout',
                retryable: true,
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        $body = $this->assertGraphSuccess($response, submission: false);
        if (($body['id'] ?? null) !== $publication->externalPublicationId
            || ($body['media_type'] ?? null) !== 'IMAGE') {
            throw new PublishingAdapterException('Meta returned a media object that does not match the requested Instagram publication.', 'invalid_adapter_response');
        }

        return new PublishingAdapterResult(
            externalId: $publication->externalPublicationId,
            externalUrl: $this->secureUrl($body['permalink'] ?? null),
            status: 'published',
            correlationId: $publication->correlationId,
            idempotencyKey: $publication->idempotencyKey,
            payload: [
                'network' => 'instagram',
                'media_type' => $body['media_type'],
                'timestamp' => is_string($body['timestamp'] ?? null) ? $body['timestamp'] : null,
            ],
        );
    }

    private function resolveConnection(?int $connectionId, ?string $credentialReference): IntegrationConnection
    {
        if ($connectionId === null || $credentialReference === null) {
            throw new PublishingAdapterException('Instagram publishing requires an authorized Instagram Login connection.', 'authentication_required');
        }

        $connection = IntegrationConnection::query()->find($connectionId);
        if ($connection === null
            || ! in_array($connection->provider, ['meta', 'instagram'], true)
            || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $credentialReference)) {
            throw new PublishingAdapterException('The Instagram connection is unavailable or does not match the publication credential reference.', 'authentication_required');
        }

        return $connection;
    }

    private function instagramAccessToken(IntegrationConnection $connection, string $instagramUserId, string $requiredScope): string
    {
        try {
            $userToken = $this->credentials->resolveAccessToken($connection, 'instagram');
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException(
                'Instagram authorization is unavailable and must be renewed before publishing.',
                'authentication_required',
                previous: $exception,
            );
        }

        if ($tokens->scopes !== [] && ! in_array($requiredScope, $tokens->scopes, true)) {
            throw new PublishingAdapterException('The Instagram authorization grant lacks a required permission; review app scopes and authorize the connection again.', 'permission_denied');
        }
        if ($tokens->scopes === [] && $connection->provider === 'instagram') {
            throw new PublishingAdapterException('The Instagram Login grant has no verifiable scope metadata; reconnect the account.', 'permission_denied');
        }
        if ($tokens->scopes === [] && $connection->provider === 'meta') {
            $this->assertLegacyGrantedScope($userToken, $requiredScope);
        }

        $destinationToken = $tokens->destinationTokens['instagram:'.$instagramUserId] ?? null;
        $accessToken = is_array($destinationToken) ? $destinationToken['access_token'] : null;
        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new PublishingAdapterException('No destination token is stored for this Instagram professional account. Reconnect Meta and select the linked account again.', 'authentication_required');
        }

        $expiresAt = $destinationToken['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                if (CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now()->addMinute())) {
                    throw new PublishingAdapterException('The stored Instagram destination token has expired. Reconnect Meta and select the account again.', 'authentication_required');
                }
            } catch (PublishingAdapterException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new PublishingAdapterException('The stored Instagram token expiry is invalid; reconnect Instagram and select the account again.', 'authentication_required', previous: $exception);
            }
        } elseif ($expiresAt !== null) {
            throw new PublishingAdapterException('The stored Instagram token expiry is invalid; reconnect Instagram and select the account again.', 'authentication_required');
        }

        return $accessToken;
    }

    /** @param list<array<string, mixed>> $media
     * @return list<array<string, mixed>>
     */
    private function validatedMedia(array $media): array
    {
        if (count($media) !== 1) {
            throw new PublishingAdapterException('Instagram publishing currently supports exactly one JPEG image.', 'unsupported_media');
        }

        $item = $media[0];
        if (($item['mime_type'] ?? null) !== 'image/jpeg') {
            throw new PublishingAdapterException('Instagram publishing currently supports JPEG images only.', 'unsupported_media');
        }

        $size = $item['size'] ?? null;
        if (! is_numeric($size) || (int) $size < 1 || (int) $size > self::MAX_IMAGE_SIZE_BYTES) {
            throw new PublishingAdapterException('The Instagram JPEG must have a known size no greater than 8 MiB.', 'media_size_exceeded');
        }

        if (($item['disk'] ?? null) !== 'r2' || ! is_string($item['path'] ?? null) || trim($item['path']) === '') {
            throw new PublishingAdapterException('Instagram publishing requires an active JPEG image stored in CR8OR R2.', 'media_unavailable');
        }

        $path = trim($item['path']);
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new PublishingAdapterException('The Instagram image storage path is invalid.', 'media_unavailable');
        }

        $metadata = $item['metadata'] ?? [];
        $dimensions = $item['dimensions'] ?? (is_array($metadata) ? ($metadata['dimensions'] ?? $metadata) : []);
        $width = is_array($dimensions) ? ($dimensions['width'] ?? null) : null;
        $height = is_array($dimensions) ? ($dimensions['height'] ?? null) : null;
        if (! is_numeric($width) || ! is_numeric($height) || (int) $width < 320 || (int) $width > 1440 || (int) $height < 320) {
            throw new PublishingAdapterException('Instagram JPEG dimensions must be known, at least 320 pixels on each side, and no wider than 1440 pixels.', 'invalid_media_dimensions');
        }

        $ratio = (float) $width / (float) $height;
        if ($ratio < 0.8 || $ratio > 1.91) {
            throw new PublishingAdapterException('Instagram image aspect ratio must be between 4:5 and 1.91:1.', 'invalid_media_dimensions');
        }

        return $media;
    }

    /** @param array<string, mixed> $media */
    private function validatedAltText(array $media): ?string
    {
        $metadata = $media['metadata'] ?? [];
        $altText = is_array($metadata) ? ($metadata['alt_text'] ?? null) : null;

        if ($altText === null || $altText === '') {
            return null;
        }

        if (! is_string($altText) || mb_strlen($altText) > 1000) {
            throw new PublishingAdapterException('Instagram image alt text must be a string of at most 1,000 characters.', 'invalid_media_metadata');
        }

        return $altText;
    }

    /** @param array<string, mixed> $media */
    private function temporaryMediaUrl(array $media): string
    {
        $path = (string) $media['path'];

        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists($path)) {
                throw new PublishingAdapterException('The selected Instagram image no longer exists in R2.', 'media_unavailable');
            }

            $url = $disk->temporaryUrl($path, CarbonImmutable::now()->addMinutes(15));
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('CR8OR could not prepare a temporary R2 URL for the Instagram image.', 'media_unavailable', retryable: true, previous: $exception);
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string(parse_url($url, PHP_URL_HOST))) {
            throw new PublishingAdapterException('R2 did not provide a valid HTTPS URL for the Instagram image.', 'media_unavailable');
        }

        return $url;
    }

    private function assertPublishingQuota(string $instagramUserId, string $accessToken, string $version, string $graphHost): void
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->get("{$graphHost}/{$version}/{$instagramUserId}/content_publishing_limit", [
                    'fields' => 'quota_usage,config',
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'Instagram publishing quota could not be verified before submission.',
                'timeout',
                retryable: true,
                previous: $exception,
            );
        }

        $body = $this->assertGraphSuccess($response, submission: false);
        $entries = $body['data'] ?? null;
        $quota = is_array($entries) && isset($entries[0]) && is_array($entries[0])
            ? $entries[0]
            : $body;
        $usage = $quota['quota_usage'] ?? null;
        $config = $quota['config'] ?? [];
        $total = is_array($config) ? ($config['quota_total'] ?? null) : null;

        if (! is_numeric($usage) || ! is_numeric($total) || (int) $total < 1 || (int) $usage < 0) {
            throw new PublishingAdapterException(
                'Instagram did not return a valid Instagram publishing quota; submission is blocked until the quota can be verified.',
                'invalid_adapter_response',
            );
        }

        if ((int) $usage >= (int) $total) {
            throw new PublishingAdapterException(
                'The Instagram professional account has reached its current content-publishing quota.',
                'publishing_quota_exceeded',
            );
        }
    }

    private function awaitContainerReady(string $containerId, string $accessToken, string $version, string $graphHost): void
    {
        $attempts = max(1, min(10, (int) config('publishing.instagram.container_poll_attempts', 5)));
        $intervalMs = max(0, min(1000, (int) config('publishing.instagram.container_poll_interval_ms', 250)));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->timeout((int) config('social_authorization.timeout', 15))
                    ->withToken($accessToken)
                    ->get("{$graphHost}/{$version}/".rawurlencode($containerId), [
                        'fields' => 'id,status_code',
                    ]);
            } catch (ConnectionException $exception) {
                throw new PublishingAdapterException('Instagram media-container processing status could not be verified.', 'timeout', retryable: true, previous: $exception);
            }

            $body = $this->assertGraphSuccess($response, submission: false);
            if (($body['id'] ?? null) !== $containerId) {
                throw new PublishingAdapterException('Meta returned a different Instagram media container during processing.', 'invalid_adapter_response');
            }

            $status = $body['status_code'] ?? null;
            if ($status === 'FINISHED') {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new PublishingAdapterException('Meta could not process the Instagram media container; no publication was submitted.', 'media_processing_failed');
            }

            if ($status === 'PUBLISHED') {
                throw new PublishingAdapterException(
                    'Instagram reports that this Instagram media container is already published; reconcile the remote media before any retry.',
                    'ambiguous_publication',
                    retryable: false,
                    ambiguous: true,
                );
            }

            if ($status !== 'IN_PROGRESS') {
                throw new PublishingAdapterException('Meta returned an unknown Instagram media-container status.', 'invalid_adapter_response');
            }

            if ($attempt + 1 < $attempts && $intervalMs > 0) {
                usleep($intervalMs * 1000);
            }
        }

        throw new PublishingAdapterException('Instagram media processing did not finish within the configured polling window; no publication was submitted.', 'media_processing_timeout', retryable: true);
    }

    /** @return array<string, mixed> */
    private function assertGraphSuccess(Response $response, bool $submission): array
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : null;
        $errorCode = is_array($error) && is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

        if ($response->successful() && $error === null) {
            if (is_array($body)) {
                return $body;
            }

            throw new PublishingAdapterException(
                'Meta returned an invalid response to the Instagram publishing request.',
                'invalid_adapter_response',
                retryable: $submission,
                ambiguous: $submission,
            );
        }

        if ($response->status() === 429 || in_array($errorCode, [4, 17, 32, 613, 80001], true)) {
            throw new PublishingAdapterException('Meta rate-limited the Instagram operation; retry after the provider limit resets.', 'rate_limited', retryable: true);
        }

        if ($response->status() === 401 || $errorCode === 190) {
            throw new PublishingAdapterException('Instagram rejected the Instagram destination token; reconnect Instagram Login.', 'authentication_required');
        }

        if ($response->status() === 403 || in_array($errorCode, [10, 200], true)) {
            throw new PublishingAdapterException('Meta denied this operation because the account, permission grant or app access is insufficient.', 'permission_denied');
        }

        if ($response->serverError()) {
            throw new PublishingAdapterException(
                'Meta returned a server error; the Instagram publication outcome is unknown and must be reconciled before retrying.',
                'provider_unavailable',
                retryable: true,
                ambiguous: $submission,
            );
        }

        throw new PublishingAdapterException('Instagram rejected the Instagram operation.', 'provider_rejected');
    }

    private function graphApiVersion(string $provider): string
    {
        $version = (string) config("social_authorization.providers.{$provider}.graph_api_version", 'v26.0');
        if (preg_match('/^v[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new PublishingAdapterException('The configured Instagram Graph API version must use the format vNN.N.', 'provider_configuration_invalid');
        }

        return $version;
    }

    private function graphApiHost(string $provider): string
    {
        return $provider === 'meta' ? 'https://graph.facebook.com' : 'https://graph.instagram.com';
    }

    private function assertLegacyGrantedScope(string $userToken, string $requiredScope): void
    {
        try {
            $response = Http::withoutRedirecting()->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($userToken)
                ->get('https://graph.facebook.com/'.$this->graphApiVersion('meta').'/me/permissions');
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Legacy Instagram permission status could not be verified.', 'timeout', retryable: true, previous: $exception);
        }

        $body = $this->assertGraphSuccess($response, submission: false);
        $permissions = $body['data'] ?? null;
        if (! is_array($permissions)) {
            throw new PublishingAdapterException('Legacy Instagram permission status could not be verified.', 'permission_denied');
        }

        foreach ($permissions as $permission) {
            if (is_array($permission)
                && ($permission['permission'] ?? null) === $requiredScope
                && ($permission['status'] ?? null) === 'granted') {
                return;
            }
        }

        throw new PublishingAdapterException('The legacy Meta grant lacks the required Instagram permission; reconnect Instagram Login.', 'permission_denied');
    }

    private function instagramUserId(string $destinationExternalId): string
    {
        if (preg_match('/^[0-9]+$/D', $destinationExternalId) !== 1) {
            throw new PublishingAdapterException('Instagram publishing requires a numeric Instagram professional-account ID, not a Facebook Page ID or username.', 'invalid_destination');
        }

        return $destinationExternalId;
    }

    private function secureUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        return $url;
    }
}