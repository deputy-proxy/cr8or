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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class LinkedInPublishingAdapter implements PublishingStatusLookupAdapter
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function network(): string
    {
        return 'linkedin';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'linkedin';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'linkedin',
            supportedAccountTypes: ['linkedin.member', 'linkedin.organization'],
            supportedDestinationTypes: ['linkedin.member', 'linkedin.organization'],
            supportedMediaTypes: [],
            maxTextLength: 3000,
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported,
            editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported,
            analytics: PublishingCapabilitySupport::Unsupported,
            // CR8OR persists the publication identity and blocks resubmission after ambiguous outcomes.
            // LinkedIn's Posts API does not provide a remote idempotency-key contract.
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The LinkedIn adapter received a request for another network.', 'network_mismatch');
        }

        if ($request->media !== []) {
            throw new PublishingAdapterException('LinkedIn media publishing is not implemented by this adapter yet; submit a text-only post.', 'unsupported_media');
        }

        if (trim($request->content) === '') {
            throw new PublishingAdapterException('LinkedIn text posts require non-empty commentary.', 'empty_content');
        }

        if (mb_strlen($request->content) > 3000) {
            throw new PublishingAdapterException('LinkedIn commentary exceeds the 3000-character limit.', 'content_too_long');
        }

        $author = $this->authorUrn($request->destinationExternalId);
        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference);
        $accessToken = $this->resolveAccessToken($connection);
        $apiVersion = $this->apiVersion();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->withHeaders([
                    'Linkedin-Version' => $apiVersion,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->post('https://api.linkedin.com/rest/posts', [
                    'author' => $author,
                    'commentary' => $request->content,
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false,
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'LinkedIn did not return a definitive response; publication outcome is unknown and must be reconciled before retrying.',
                'timeout',
                retryable: true,
                previous: $exception,
                ambiguous: true,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new PublishingAdapterException('LinkedIn rejected the connection or required publishing permissions.', 'authentication_required');
        }

        if ($response->status() === 429) {
            throw new PublishingAdapterException('LinkedIn rate limit reached; retry the same publication later.', 'rate_limited', retryable: true);
        }

        if ($response->serverError()) {
            throw new PublishingAdapterException(
                'LinkedIn returned a server error after the publication request; the outcome is unknown and must be reconciled before retrying.',
                'provider_error',
                retryable: true,
                ambiguous: true,
            );
        }

        if ($response->status() !== 201) {
            throw new PublishingAdapterException('LinkedIn rejected the publication request.', 'provider_rejected');
        }

        $externalId = trim($response->header('x-restli-id'));
        if ($externalId === '') {
            throw new PublishingAdapterException(
                'LinkedIn accepted the request but did not return its post identifier; the outcome is unknown and must be reconciled before retrying.',
                'invalid_adapter_response',
                retryable: true,
                ambiguous: true,
            );
        }

        return new PublishingAdapterResult(
            externalId: $externalId,
            externalUrl: null,
            status: 'published',
            correlationId: $request->correlationId,
            idempotencyKey: $request->idempotencyKey,
            payload: [
                'network' => 'linkedin',
                'destination_external_id' => $request->destinationExternalId,
                'content_type' => 'text',
            ],
        );
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The LinkedIn adapter received a status lookup for another network.', 'network_mismatch');
        }

        $author = $this->authorUrn($publication->destinationExternalId);
        if (preg_match('/^urn:li:(?:share|ugcPost):[A-Za-z0-9_-]+$/D', $publication->externalPublicationId) !== 1) {
            throw new PublishingAdapterException('LinkedIn status lookup requires a canonical post URN.', 'invalid_destination');
        }

        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference);
        $accessToken = $this->resolveAccessToken($connection);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($accessToken)
                ->withHeaders([
                    'Linkedin-Version' => $this->apiVersion(),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->get('https://api.linkedin.com/rest/posts/'.rawurlencode($publication->externalPublicationId), [
                    'viewContext' => 'AUTHOR',
                ]);
        } catch (ConnectionException $exception) {
            throw new PublishingAdapterException(
                'LinkedIn status lookup failed before a definitive response was received.',
                'timeout',
                retryable: true,
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new PublishingAdapterException('LinkedIn rejected the connection or required publishing permissions.', 'authentication_required');
        }

        if ($response->status() === 429) {
            throw new PublishingAdapterException('LinkedIn rate limit reached during status lookup.', 'rate_limited', retryable: true);
        }

        if ($response->serverError()) {
            throw new PublishingAdapterException('LinkedIn returned a server error during status lookup.', 'provider_error', retryable: true);
        }

        if (! $response->successful()) {
            throw new PublishingAdapterException('LinkedIn rejected the status lookup request.', 'provider_rejected');
        }

        $body = $response->json();
        if (! is_array($body)
            || ($body['id'] ?? null) !== $publication->externalPublicationId
            || ($body['author'] ?? null) !== $author) {
            throw new PublishingAdapterException('LinkedIn returned a post that does not match the requested publication and destination.', 'invalid_adapter_response');
        }

        $status = match ($body['lifecycleState'] ?? null) {
            'PUBLISHED' => 'published',
            'DRAFT', 'PUBLISH_REQUESTED' => 'pending',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        return new PublishingAdapterResult(
            externalId: $publication->externalPublicationId,
            externalUrl: null,
            status: $status,
            correlationId: $publication->correlationId,
            idempotencyKey: $publication->idempotencyKey,
            payload: [
                'network' => 'linkedin',
                'lifecycle_state' => $body['lifecycleState'],
                'published_at' => is_numeric($body['publishedAt'] ?? null) ? (int) $body['publishedAt'] : null,
            ],
        );
    }

    private function resolveConnection(?int $connectionId, ?string $credentialReference): IntegrationConnection
    {
        if ($connectionId === null || $credentialReference === null) {
            throw new PublishingAdapterException('LinkedIn publishing requires an authorized integration connection.', 'authentication_required');
        }

        $connection = IntegrationConnection::query()->find($connectionId);
        if ($connection === null
            || $connection->provider !== 'linkedin'
            || ! $connection->isOperational()
            || ! hash_equals((string) $connection->credential_reference, $credentialReference)) {
            throw new PublishingAdapterException('The LinkedIn connection is unavailable or does not match the publication credential reference.', 'authentication_required');
        }

        return $connection;
    }

    private function resolveAccessToken(IntegrationConnection $connection): string
    {
        try {
            $accessToken = $this->credentials->resolveAccessToken($connection, 'linkedin');
        } catch (SocialAuthorizationException $exception) {
            throw new PublishingAdapterException(
                'LinkedIn authorization is unavailable and must be renewed before using publishing operations.',
                'authentication_required',
                previous: $exception,
            );
        }

        if (trim($accessToken) === '') {
            throw new PublishingAdapterException('LinkedIn authorization did not provide an access token.', 'authentication_required');
        }

        return $accessToken;
    }

    private function apiVersion(): string
    {
        $apiVersion = (string) config('publishing.linkedin.api_version', '202609');
        if (preg_match('/^[0-9]{6}$/D', $apiVersion) !== 1) {
            throw new PublishingAdapterException('The configured LinkedIn API version must use YYYYMM format.', 'provider_configuration_invalid');
        }

        return $apiVersion;
    }

    private function authorUrn(string $destinationExternalId): string
    {
        if (preg_match('/^urn:li:(person|organization):[A-Za-z0-9_-]+$/D', $destinationExternalId) !== 1) {
            throw new PublishingAdapterException(
                'LinkedIn destination IDs must be canonical person or organization URNs; ambiguous plain identifiers are not accepted.',
                'invalid_destination',
            );
        }

        return $destinationExternalId;
    }
}
