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

final class XPublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_WEIGHTED_TEXT_LENGTH = 280;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
    ) {}

    public function network(): string
    {
        return 'x';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'x';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['publication.publish', 'publication.status.lookup'], true);
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'x',
            supportedAccountTypes: ['x.user'],
            supportedDestinationTypes: ['x.user'],
            supportedMediaTypes: [],
            maxTextLength: self::MAX_WEIGHTED_TEXT_LENGTH,
            maxMediaItems: null,
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
            throw new PublishingAdapterException('The X adapter received a request for another network.', 'network_mismatch');
        }
        if ($request->media !== []) {
            throw new PublishingAdapterException('X media publishing is not enabled until the configured API access and media-upload contract are verified. Text-only posts are supported.', 'unsupported_media');
        }

        $text = trim($request->content);
        if ($text === '') {
            throw new PublishingAdapterException('X posts require non-empty text.', 'empty_content');
        }
        $weightedLength = $this->weightedTextLength($text);
        if ($weightedLength > self::MAX_WEIGHTED_TEXT_LENGTH) {
            throw new PublishingAdapterException('X post text exceeds the 280 weighted-character limit.', 'content_too_long');
        }

        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference, $request->destinationExternalId);
        $token = $this->accessToken($connection, ['users.read', 'tweet.read', 'tweet.write']);
        $job = $this->resolveJob($request, $connection);
        $fingerprint = hash('sha256', $request->destinationExternalId."\0".$text);
        $state = $job->adapterState();

        if ($state !== []) {
            $this->assertMatchingState($state, $request->destinationExternalId, $fingerprint);
            if (($state['phase'] ?? null) === 'published' && is_string($state['post_id'] ?? null)) {
                return $this->result($request, $request->destinationExternalId, $state['post_id'], 'published', $state);
            }
            if (($state['phase'] ?? null) === 'submitting') {
                throw new PublishingAdapterException('The X post request has an ambiguous outcome. X does not provide a create-post idempotency key, so automatic resubmission is blocked to prevent duplicate posts.', 'ambiguous_publish_outcome', retryable: true, ambiguous: true);
            }
        }

        $this->assertAuthenticatedDestination($token, $request->destinationExternalId);
        $state = [
            'phase' => 'submitting',
            'destination_external_id' => $request->destinationExternalId,
            'request_fingerprint' => $fingerprint,
            'started_at' => now()->utc()->toIso8601String(),
        ];
        $job->setAdapterState($state)->save();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->post($this->baseUrl().'/tweets', ['text' => $text]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('X did not return a definitive create-post response. The outcome is unknown and the request will not be submitted again automatically.', 'timeout', retryable: true, previous: $exception, ambiguous: true);
        }

        if (! $response->successful()) {
            $failure = $this->apiFailure($response);
            if (! $failure->ambiguous) {
                $state['phase'] = 'ready';
                $job->setAdapterState($state)->save();
            }
            throw $failure;
        }

        $postId = $response->json('data.id');
        if (! is_string($postId) || preg_match('/^[0-9]{1,30}$/D', $postId) !== 1) {
            throw new PublishingAdapterException('X accepted the create-post request without returning a valid post ID. Reconcile the remote outcome before retrying.', 'invalid_adapter_response', retryable: true, ambiguous: true);
        }

        $state['phase'] = 'published';
        $state['post_id'] = $postId;
        $state['published_at'] = now()->utc()->toIso8601String();
        $job->setAdapterState($state)->save();

        return $this->result($request, $request->destinationExternalId, $postId, 'published', $state);
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The X adapter received a status lookup for another network.', 'network_mismatch');
        }
        if (preg_match('/^[0-9]{1,30}$/D', $publication->externalPublicationId) !== 1) {
            throw new PublishingAdapterException('X status lookup requires a numeric post ID.', 'invalid_publication_id');
        }

        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference, $publication->destinationExternalId);
        $token = $this->accessToken($connection, ['users.read', 'tweet.read']);
        $job = $this->resolveRemoteJob($publication, $connection);
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->get($this->baseUrl().'/tweets/'.rawurlencode($publication->externalPublicationId), [
                    'tweet.fields' => 'author_id,created_at,text',
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('X post status could not be retrieved.', 'provider_unavailable', retryable: true, previous: $exception);
        }

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw $this->apiFailure($response);
        }

        $data = $response->json('data');
        if (! is_array($data) || ! is_string($data['id'] ?? null)
            || ! hash_equals($publication->externalPublicationId, $data['id'])
            || ! is_string($data['author_id'] ?? null)
            || ! hash_equals($publication->destinationExternalId, $data['author_id'])) {
            throw new PublishingAdapterException('X returned a post that does not match the requested ID and authorized destination.', 'invalid_adapter_response');
        }

        $state = $job->adapterState();
        if ($state !== []) {
            $this->assertMatchingState($state, $publication->destinationExternalId, is_string($state['request_fingerprint'] ?? null) ? $state['request_fingerprint'] : '', allowMissingFingerprint: true);
        }

        return new PublishingAdapterResult(
            externalId: $publication->externalPublicationId,
            externalUrl: $this->postUrl($publication->externalPublicationId),
            status: 'published',
            correlationId: $publication->correlationId,
            idempotencyKey: $publication->idempotencyKey,
            payload: [
                'network' => 'x',
                'destination_external_id' => $publication->destinationExternalId,
                'created_at' => is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
            ],
        );
    }

    private function resolveConnection(?int $id, ?string $reference, string $destination): IntegrationConnection
    {
        $connection = $id === null ? null : IntegrationConnection::query()->find($id);
        $identity = $connection?->socialIdentity;
        if ($connection === null || $reference === null || $connection->provider !== 'x' || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $reference)
            || $identity === null || $identity->provider !== 'x'
            || (int) $identity->organization_id !== (int) $connection->organization_id
            || ! hash_equals((string) $identity->external_id, (string) $connection->external_account_id)
            || ! hash_equals((string) $connection->external_account_id, $destination)) {
            throw new PublishingAdapterException('The X connection, identity or credential reference does not match the requested X user.', 'authentication_required');
        }

        return $connection;
    }

    /** @param list<string> $requiredScopes */
    private function accessToken(IntegrationConnection $connection, array $requiredScopes): string
    {
        try {
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('X authorization is unavailable and must be renewed.', 'authentication_required', previous: $exception);
        }
        foreach ($requiredScopes as $scope) {
            if (! in_array($scope, $tokens->scopes, true)) {
                throw new PublishingAdapterException('The X authorization grant is missing a required user-context scope.', 'permission_denied');
            }
        }
        try {
            $token = $this->credentials->resolveAccessToken($connection, 'x');
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException('X authorization is unavailable and must be renewed.', 'authentication_required', previous: $exception);
        }
        if (trim($token) === '') {
            throw new PublishingAdapterException('X authorization did not provide an access token.', 'authentication_required');
        }

        return $token;
    }

    private function assertAuthenticatedDestination(string $token, string $destination): void
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($token)
                ->get($this->baseUrl().'/users/me', ['user.fields' => 'id,username']);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException('X could not verify the authorized user before publishing.', 'provider_unavailable', retryable: true, previous: $exception);
        }
        if (! $response->successful()) {
            throw $this->apiFailure($response);
        }
        $id = $response->json('data.id');
        if (! is_string($id) || ! hash_equals($destination, $id)) {
            throw new PublishingAdapterException('The authenticated X user does not match the selected publishing destination.', 'destination_mismatch');
        }
    }

    private function resolveJob(PublishingAdapterRequest $request, IntegrationConnection $connection): PublishingJob
    {
        if ($request->publishingJobId === null) {
            throw new PublishingAdapterException('X publishing requires a persisted publishing job for safe retries.', 'publishing_job_required');
        }
        $job = PublishingJob::query()->find($request->publishingJobId);
        if ($job === null || $job->status !== PublishingJob::STATUS_RUNNING
            || ! hash_equals((string) $job->idempotency_key, 'publish-'.$request->idempotencyKey)
            || ($job->network !== null && $job->network !== 'x')
            || ($job->destination_external_id !== null && ! hash_equals((string) $job->destination_external_id, $request->destinationExternalId))
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $job->enterprise_id)) {
            throw new PublishingAdapterException('The X publishing job does not match the connection, destination or publication request.', 'publishing_job_mismatch');
        }

        return $job;
    }

    private function resolveRemoteJob(PublishingRemotePublication $publication, IntegrationConnection $connection): PublishingJob
    {
        if ($publication->publishingJobId === null) {
            throw new PublishingAdapterException('X status reconciliation requires a correlated publishing job.', 'publishing_job_required');
        }
        $job = PublishingJob::query()->find($publication->publishingJobId);
        if ($job === null || ! in_array($job->status, [PublishingJob::STATUS_SUCCEEDED, PublishingJob::STATUS_UNKNOWN], true)
            || ! hash_equals((string) $job->idempotency_key, 'publish-'.$publication->idempotencyKey)
            || ($job->network !== null && $job->network !== 'x')
            || ($job->destination_external_id !== null && ! hash_equals((string) $job->destination_external_id, $publication->destinationExternalId))
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $job->enterprise_id)) {
            throw new PublishingAdapterException('The X publishing job does not match the status lookup destination or connection.', 'publishing_job_mismatch');
        }

        return $job;
    }

    private function weightedTextLength(string $text): int
    {
        $text = preg_replace_callback('~https?://[^\s]+~iu', static function (array $match): string {
            $url = rtrim($match[0], '.,!?;:)]}');
            $trailing = substr($match[0], strlen($url));

            return str_repeat('x', 23).$trailing;
        }, $text) ?? $text;
        preg_match_all('/\X/u', $text, $matches);
        $length = 0;
        foreach ($matches[0] as $cluster) {
            if (preg_match('/[\\p{Extended_Pictographic}\\p{Regional_Indicator}]/u', $cluster) === 1) {
                $length += 2;

                continue;
            }

            preg_match_all('/./us', $cluster, $characters);
            foreach ($characters[0] as $character) {
                $codepoint = mb_ord($character, 'UTF-8');
                $singleWeight = $codepoint <= 4351
                    || ($codepoint >= 8192 && $codepoint <= 8205)
                    || ($codepoint >= 8208 && $codepoint <= 8223)
                    || ($codepoint >= 8242 && $codepoint <= 8247);
                $length += $singleWeight ? 1 : 2;
            }
        }

        return $length;
    }

    /** @param array<string, mixed> $state */
    private function assertMatchingState(array $state, string $destination, string $fingerprint, bool $allowMissingFingerprint = false): void
    {
        if (! is_string($state['destination_external_id'] ?? null) || ! hash_equals($destination, $state['destination_external_id'])
            || (! $allowMissingFingerprint && (! is_string($state['request_fingerprint'] ?? null) || ! hash_equals($fingerprint, $state['request_fingerprint'])))) {
            throw new PublishingAdapterException('The X idempotency key was already used for another destination or different content.', 'idempotency_conflict');
        }
    }

    private function apiFailure(Response $response): PublishingAdapterException
    {
        $message = strtolower((string) ($response->json('detail')
            ?? $response->json('title')
            ?? $response->json('errors.0.detail')
            ?? $response->json('errors.0.title')
            ?? ''));
        $status = $response->status();
        if (str_contains($message, 'usage cap') || str_contains($message, 'monthly product cap') || str_contains($message, 'quota exhausted')) {
            return new PublishingAdapterException('The configured X API usage quota is exhausted. Verify the product cap and reset window in the developer console before retrying.', 'quota_exhausted');
        }
        if ($status === 429) {
            return new PublishingAdapterException('X rate limiting was reached. Respect the provider reset window before retrying.', 'rate_limited', retryable: true);
        }
        if ($status === 401) {
            return new PublishingAdapterException('X rejected the user authorization. Reauthorize the account before retrying.', 'authentication_required');
        }
        if ($status === 403 && (str_contains($message, 'access level') || str_contains($message, 'access tier') || str_contains($message, 'subscription') || str_contains($message, 'plan'))) {
            return new PublishingAdapterException('The configured X API access plan does not permit this operation or its usage quota has been reached.', 'access_tier_restricted');
        }
        if ($status === 403) {
            return new PublishingAdapterException('X denied this operation. Verify user-context scopes, app permissions and account eligibility.', 'permission_denied');
        }
        if ($status >= 500) {
            return new PublishingAdapterException('X returned a server error. The remote operation may have completed; reconcile before retrying.', 'provider_unavailable', retryable: true, ambiguous: true);
        }

        return new PublishingAdapterException('X rejected the request. Verify post content, account eligibility, API access and request parameters.', 'provider_error');
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('publishing.x.api_base_url', 'https://api.x.com/2'), '/');
        if (! app()->environment('testing')) {
            $parts = parse_url($base);
            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ($parts['host'] ?? null) !== 'api.x.com'
                || ($parts['path'] ?? null) !== '/2'
                || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
                throw new PublishingAdapterException('X API base URL must use the official HTTPS API origin and /2 path.', 'provider_configuration_invalid');
            }
        }

        return $base;
    }

    private function postUrl(string $postId): string
    {
        return 'https://x.com/i/web/status/'.rawurlencode($postId);
    }

    /** @param array<string, mixed> $state */
    private function result(PublishingAdapterRequest|PublishingRemotePublication $request, string $destination, string $postId, string $status, array $state): PublishingAdapterResult
    {
        return new PublishingAdapterResult(
            externalId: $postId,
            externalUrl: $this->postUrl($postId),
            status: $status,
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: [
                'network' => 'x',
                'destination_external_id' => $destination,
                'post_id' => $postId,
                'request_fingerprint' => is_string($state['request_fingerprint'] ?? null) ? $state['request_fingerprint'] : null,
            ],
        );
    }
}