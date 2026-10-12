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

final class FacebookPublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_TEXT_LENGTH = 63206;

    private const MAX_IMAGE_SIZE_BYTES = 10_485_760;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
    ) {}

    public function network(): string
    {
        return 'facebook';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'facebook';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'facebook',
            supportedAccountTypes: ['facebook.page'],
            supportedDestinationTypes: ['facebook.page'],
            supportedMediaTypes: ['image/jpeg', 'image/png'],
            maxTextLength: self::MAX_TEXT_LENGTH,
            maxMediaItems: 1,
            maxMediaSizeBytes: self::MAX_IMAGE_SIZE_BYTES,
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported,
            editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported,
            analytics: PublishingCapabilitySupport::Unsupported,
            // CR8OR's durable publication identity and ambiguous-outcome lockout are the safe retry boundary.
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The Facebook adapter received a request for another network.', 'network_mismatch');
        }

        $pageId = $this->pageId($request->destinationExternalId);
        $media = $this->validatedMedia($request->media);
        if (trim($request->content) === '' && $media === []) {
            throw new PublishingAdapterException('Facebook Page posts require text or one supported image.', 'empty_content');
        }
        if (mb_strlen($request->content) > self::MAX_TEXT_LENGTH) {
            throw new PublishingAdapterException('Facebook Page post text exceeds the supported character limit.', 'content_too_long');
        }

        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference);
        $pageToken = $this->pageAccessToken($connection, $pageId, 'pages_manage_posts');
        $version = $this->graphApiVersion();

        try {
            if ($media !== []) {
                $mediaUrl = $this->temporaryMediaUrl($media[0]);
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout((int) config('social_authorization.timeout', 15))
                    ->withToken($pageToken)
                    ->post("https://graph.facebook.com/{$version}/{$pageId}/photos", [
                        'url' => $mediaUrl,
                        'caption' => $request->content,
                        'published' => 'true',
                    ]);
            } else {
                $payload = ['message' => $request->content];
                $link = $this->firstHttpsLink($request->content);
                if ($link !== null) {
                    $payload['link'] = $link;
                }

                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout((int) config('social_authorization.timeout', 15))
                    ->withToken($pageToken)
                    ->post("https://graph.facebook.com/{$version}/{$pageId}/feed", $payload);
            }
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'Meta did not return a definitive response; the Facebook Page publication outcome is unknown and must be reconciled before retrying.',
                'timeout',
                retryable: true,
                previous: $exception,
                ambiguous: true,
            );
        }

        $body = $this->assertGraphSuccess($response, submission: true);
        $externalId = $media !== [] ? ($body['post_id'] ?? null) : ($body['id'] ?? null);
        if (! is_string($externalId) || ! $this->isPostIdForPage($externalId, $pageId)) {
            throw new PublishingAdapterException(
                'Meta accepted the request without returning a valid Facebook Page post identifier; the outcome is unknown and must be reconciled before retrying.',
                'invalid_adapter_response',
                retryable: true,
                ambiguous: true,
            );
        }

        return new PublishingAdapterResult(
            externalId: $externalId,
            externalUrl: $this->secureUrl($body['permalink_url'] ?? null),
            status: 'published',
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: [
                'network' => 'facebook',
                'destination_external_id' => $pageId,
                'content_type' => $media !== [] ? 'image' : ($this->firstHttpsLink($request->content) !== null ? 'link' : 'text'),
                'media_count' => count($media),
            ],
        );
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The Facebook adapter received a status lookup for another network.', 'network_mismatch');
        }

        $pageId = $this->pageId($publication->destinationExternalId);
        if (! $this->isPostIdForPage($publication->externalPublicationId, $pageId)) {
            throw new PublishingAdapterException('Facebook status lookup requires a post ID belonging to the selected Page.', 'invalid_destination');
        }

        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference);
        $pageToken = $this->pageAccessToken($connection, $pageId, 'pages_read_engagement');
        $version = $this->graphApiVersion();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($pageToken)
                ->get("https://graph.facebook.com/{$version}/".rawurlencode($publication->externalPublicationId), [
                    'fields' => 'id,is_published,permalink_url,created_time',
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'Facebook status lookup did not return a definitive response.',
                'timeout',
                retryable: true,
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        $body = $this->assertGraphSuccess($response, submission: false);
        $remoteId = $body['id'];
        if (! is_string($remoteId)
            || $remoteId !== $publication->externalPublicationId
            || ! $this->isPostIdForPage($remoteId, $pageId)) {
            throw new PublishingAdapterException('Meta returned a post that does not match the requested Facebook Page publication.', 'invalid_adapter_response');
        }

        if (($body['is_published'] ?? null) !== true) {
            return null;
        }

        return new PublishingAdapterResult(
            externalId: $publication->externalPublicationId,
            externalUrl: $this->secureUrl($body['permalink_url'] ?? null),
            status: 'published',
            correlationId: $publication->correlationId,
            idempotencyKey: $publication->idempotencyKey,
            payload: [
                'network' => 'facebook',
                'is_published' => true,
                'created_time' => is_string($body['created_time'] ?? null) ? $body['created_time'] : null,
            ],
        );
    }

    private function resolveConnection(?int $connectionId, ?string $credentialReference): IntegrationConnection
    {
        if ($connectionId === null || $credentialReference === null) {
            throw new PublishingAdapterException('Facebook publishing requires an authorized Meta connection.', 'authentication_required');
        }

        $connection = IntegrationConnection::query()->find($connectionId);
        if ($connection === null
            || $connection->provider !== 'meta'
            || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $credentialReference)) {
            throw new PublishingAdapterException('The Meta connection is unavailable or does not match the publication credential reference.', 'authentication_required');
        }

        return $connection;
    }

    private function pageAccessToken(IntegrationConnection $connection, string $pageId, string $requiredScope): string
    {
        try {
            // Resolve the shared credential first so the common lifecycle can refresh the parent Meta token.
            $userToken = $this->credentials->resolveAccessToken($connection, 'facebook');
            $credential = $this->credentialStore->forConnection($connection);
            $tokens = $credential->tokenSet();
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException(
                'Meta authorization is unavailable and must be renewed before using Facebook publishing.',
                'authentication_required',
                previous: $exception,
            );
        }

        if ($tokens->scopes !== []) {
            if (! in_array($requiredScope, $tokens->scopes, true)) {
                throw new PublishingAdapterException('The Meta authorization grant lacks a required Page permission; review the app scopes and authorize the connection again.', 'permission_denied');
            }
        } else {
            $this->assertGrantedScope($userToken, $requiredScope);
        }

        $destinationToken = $tokens->destinationTokens['facebook:'.$pageId] ?? null;
        $pageToken = is_array($destinationToken) ? $destinationToken['access_token'] : null;
        if (! is_string($pageToken) || trim($pageToken) === '') {
            throw new PublishingAdapterException('No Page access token is stored for this Facebook Page. Reconnect Meta and select the Page again.', 'authentication_required');
        }

        $expiresAt = $destinationToken['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                if (CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now()->addMinute())) {
                    throw new PublishingAdapterException('The stored Facebook Page access token has expired. Reconnect Meta and select the Page again.', 'authentication_required');
                }
            } catch (PublishingAdapterException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new PublishingAdapterException('The stored Facebook Page token expiry is invalid; reconnect Meta and select the Page again.', 'authentication_required', previous: $exception);
            }
        } elseif ($expiresAt !== null) {
            throw new PublishingAdapterException('The stored Facebook Page token expiry is invalid; reconnect Meta and select the Page again.', 'authentication_required');
        }

        return $pageToken;
    }

    private function assertGrantedScope(string $userToken, string $requiredScope): void
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($userToken)
                ->get('https://graph.facebook.com/'.$this->graphApiVersion().'/me/permissions');
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('Meta permission status could not be verified before publishing.', 'timeout', retryable: true, previous: $exception);
        }

        $body = $this->assertGraphSuccess($response, submission: false);
        $permissions = $body['data'] ?? null;
        if (! is_array($permissions)) {
            throw new PublishingAdapterException('Meta did not return permission status; publishing is blocked until the grant can be verified.', 'permission_denied');
        }

        foreach ($permissions as $permission) {
            if (is_array($permission)
                && ($permission['permission'] ?? null) === $requiredScope
                && ($permission['status'] ?? null) === 'granted') {
                return;
            }
        }

        throw new PublishingAdapterException('Meta has not granted the required Page permission; review the app scopes and authorize the connection again.', 'permission_denied');
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private function validatedMedia(array $media): array
    {
        if (count($media) > 1) {
            throw new PublishingAdapterException('Facebook Page publishing currently supports one image per post.', 'too_many_media_items');
        }

        foreach ($media as $item) {
            $mimeType = $item['mime_type'] ?? null;
            if (! is_string($mimeType) || ! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                throw new PublishingAdapterException('Facebook Page publishing supports JPEG and PNG images only.', 'unsupported_media');
            }

            $size = $item['size'] ?? null;
            if (! is_numeric($size) || (int) $size < 1 || (int) $size > self::MAX_IMAGE_SIZE_BYTES) {
                throw new PublishingAdapterException('The Facebook image must have a known size no greater than 10 MiB.', 'media_size_exceeded');
            }

            if (($item['disk'] ?? null) !== 'r2' || ! is_string($item['path'] ?? null) || trim($item['path']) === '') {
                throw new PublishingAdapterException('Facebook image publishing requires an active image stored in CR8OR R2.', 'media_unavailable');
            }

            $path = trim($item['path']);
            if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                throw new PublishingAdapterException('The Facebook image storage path is invalid.', 'media_unavailable');
            }
        }

        return $media;
    }

    /** @param array<string, mixed> $media */
    private function temporaryMediaUrl(array $media): string
    {
        $path = (string) $media['path'];

        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists($path)) {
                throw new PublishingAdapterException('The selected Facebook image no longer exists in R2.', 'media_unavailable');
            }

            $url = $disk->temporaryUrl($path, CarbonImmutable::now()->addMinutes(15));
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('CR8OR could not prepare a temporary R2 URL for the Facebook image.', 'media_unavailable', retryable: true, previous: $exception);
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string(parse_url($url, PHP_URL_HOST))) {
            throw new PublishingAdapterException('R2 did not provide a valid HTTPS URL for the Facebook image.', 'media_unavailable');
        }

        return $url;
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
                'Meta returned an invalid response to the Facebook publishing request.',
                'invalid_adapter_response',
                retryable: $submission,
                ambiguous: $submission,
            );
        }

        if ($response->status() === 429 || in_array($errorCode, [4, 17, 32, 613, 80001], true)) {
            throw new PublishingAdapterException('Meta rate-limited the Facebook Page operation; retry after the provider limit resets.', 'rate_limited', retryable: true);
        }

        if ($response->status() === 401 || $errorCode === 190) {
            throw new PublishingAdapterException('Meta rejected the Page access token; reconnect the Meta authorization.', 'authentication_required');
        }

        if ($response->status() === 403 || in_array($errorCode, [10, 200], true)) {
            throw new PublishingAdapterException('Meta denied this operation because the Page role, permission grant or app access is insufficient.', 'permission_denied');
        }

        if ($response->serverError()) {
            throw new PublishingAdapterException(
                'Meta returned a server error; the Facebook Page publication outcome is unknown and must be reconciled before retrying.',
                'provider_unavailable',
                retryable: true,
                ambiguous: $submission,
            );
        }

        throw new PublishingAdapterException('Meta rejected the Facebook Page operation.', 'provider_rejected');
    }

    private function graphApiVersion(): string
    {
        $version = (string) config('social_authorization.providers.meta.graph_api_version', 'v26.0');
        if (preg_match('/^v[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new PublishingAdapterException('The configured Meta Graph API version must use the format vNN.N.', 'provider_configuration_invalid');
        }

        return $version;
    }

    private function pageId(string $destinationExternalId): string
    {
        if (preg_match('/^[0-9]+$/D', $destinationExternalId) !== 1) {
            throw new PublishingAdapterException('Facebook publishing requires a numeric Facebook Page ID, not a profile ID or Page name.', 'invalid_destination');
        }

        return $destinationExternalId;
    }

    private function isPostIdForPage(string $postId, string $pageId): bool
    {
        return preg_match('/^'.preg_quote($pageId, '/').'_[0-9]+$/D', $postId) === 1;
    }

    private function firstHttpsLink(string $content): ?string
    {
        if (preg_match_all('~https://[^\s<>"\']+~i', $content, $matches) === false) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $candidate = rtrim($candidate, '.,;:!?)]}');
            if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $parts = parse_url($candidate);
            if (($parts['scheme'] ?? null) === 'https'
                && is_string($parts['host'] ?? null)
                && ! isset($parts['user'])
                && ! isset($parts['pass'])) {
                return $candidate;
            }
        }

        return null;
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
