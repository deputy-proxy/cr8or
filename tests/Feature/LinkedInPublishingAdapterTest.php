<?php

use App\Contracts\CredentialResolver;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingRemotePublication;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Services\Publishing\LinkedInPublishingAdapter;
use App\Services\PublishingAdapterRegistry;
use Illuminate\Support\Facades\Http;

/** @return array{Organization, IntegrationConnection, LinkedInPublishingAdapter} */
function linkedInPublishingAdapterContext(): array
{
    $organization = Organization::factory()->create();
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'linkedin',
        'external_account_id' => 'member-123',
        'credential_reference' => 'vault/linkedin/member-123',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'linkedin')
        ->andReturn('test-access-token');
    app()->instance(CredentialResolver::class, $credentials);

    return [$organization, $connection, app(LinkedInPublishingAdapter::class)];
}

function linkedInPublishingRequest(IntegrationConnection $connection, string $destination = 'urn:li:organization:123456'): PublishingAdapterRequest
{
    return new PublishingAdapterRequest(
        network: 'linkedin',
        destinationExternalId: $destination,
        content: 'A text-only LinkedIn post from CR8OR.',
        media: [],
        scheduledAt: null,
        idempotencyKey: 'publication-key-123',
        correlationId: 'correlation-123',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
}

it('declares only implemented LinkedIn capabilities and remains disabled pending deployment approval', function () {
    $adapter = app(LinkedInPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('linkedin')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.status.lookup'))->toBeFalse()
        ->and($capabilities->supportedAccountTypes)->toBe(['linkedin.member', 'linkedin.organization'])
        ->and($capabilities->supportedMediaTypes)->toBe([])
        ->and($capabilities->maxTextLength)->toBe(3000)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.linkedin.class'))->toBe(LinkedInPublishingAdapter::class)
        ->and(config('publishing.adapters.linkedin.enabled'))->toBeFalse();
});

it('resolves through the shared adapter registry only when explicitly enabled', function () {
    config(['publishing.adapters.linkedin.enabled' => true]);

    $resolution = app(PublishingAdapterRegistry::class)->resolve('linkedin');

    expect($resolution->adapter)->toBeInstanceOf(LinkedInPublishingAdapter::class)
        ->and($resolution->adapterKey)->toBe('linkedin')
        ->and($resolution->network)->toBe('linkedin')
        ->and($resolution->useNativeScheduling)->toBeFalse();
});

it('publishes text-only posts to canonical organization URNs using the versioned Posts API', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:987654321']),
    ]);

    $result = $adapter->publish(linkedInPublishingRequest($connection));

    expect($result->externalId)->toBe('urn:li:share:987654321')
        ->and($result->externalUrl)->toBeNull()
        ->and($result->status)->toBe('published')
        ->and($result->correlationId)->toBe('correlation-123')
        ->and($result->idempotencyKey)->toBe('publication-key-123')
        ->and($result->payload)->toBe([
            'network' => 'linkedin',
            'destination_external_id' => 'urn:li:organization:123456',
            'content_type' => 'text',
        ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.linkedin.com/rest/posts'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request->hasHeader('Linkedin-Version', '202609')
            && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $request['author'] === 'urn:li:organization:123456'
            && $request['commentary'] === 'A text-only LinkedIn post from CR8OR.'
            && $request['visibility'] === 'PUBLIC'
            && $request['lifecycleState'] === 'PUBLISHED';
    });
});

it('publishes a text-only post to a canonical member URN', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:member-post-123']),
    ]);

    $adapter->publish(linkedInPublishingRequest($connection, 'urn:li:person:member-123'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.linkedin.com/rest/posts'
        && $request['author'] === 'urn:li:person:member-123');
});

it('looks up remote post status and validates both post identity and author', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    $remote = new PublishingRemotePublication(
        network: 'linkedin',
        destinationExternalId: 'urn:li:organization:123456',
        externalPublicationId: 'urn:li:share:987654321',
        idempotencyKey: 'publication-key-123',
        correlationId: 'correlation-123',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
    Http::fake([
        'https://api.linkedin.com/rest/posts/*' => Http::response([
            'id' => 'urn:li:share:987654321',
            'author' => 'urn:li:organization:123456',
            'lifecycleState' => 'PUBLISHED',
            'publishedAt' => 1781200000000,
        ], 200),
    ]);

    $result = $adapter->lookupStatus($remote);

    expect($result?->externalId)->toBe('urn:li:share:987654321')
        ->and($result?->status)->toBe('published')
        ->and($result?->correlationId)->toBe('correlation-123')
        ->and($result?->idempotencyKey)->toBe('publication-key-123')
        ->and($result?->payload['lifecycle_state'])->toBe('PUBLISHED');

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_contains($request->url(), rawurlencode('urn:li:share:987654321'))
        && $request->url() !== 'https://api.linkedin.com/rest/posts/urn:li:share:987654321'
        && $request->hasHeader('Linkedin-Version', '202609')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
});

it('returns unknown status for a missing LinkedIn post instead of inventing a result', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    $remote = new PublishingRemotePublication(
        network: 'linkedin',
        destinationExternalId: 'urn:li:person:member-123',
        externalPublicationId: 'urn:li:share:missing',
        idempotencyKey: 'publication-key-123',
        correlationId: 'correlation-123',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
    Http::fake(['https://api.linkedin.com/rest/posts/*' => Http::response([], 404)]);

    expect($adapter->lookupStatus($remote))->toBeNull();
});

it('rejects a LinkedIn status response for a different author or post', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    $remote = new PublishingRemotePublication(
        network: 'linkedin',
        destinationExternalId: 'urn:li:organization:123456',
        externalPublicationId: 'urn:li:share:987654321',
        idempotencyKey: 'publication-key-123',
        correlationId: 'correlation-123',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
    Http::fake(['https://api.linkedin.com/rest/posts/*' => Http::response([
        'id' => 'urn:li:share:other-post',
        'author' => 'urn:li:organization:other',
        'lifecycleState' => 'PUBLISHED',
    ], 200)]);

    expect(fn () => $adapter->lookupStatus($remote))
        ->toThrow(PublishingAdapterException::class, 'does not match the requested publication');
});

it('rejects ambiguous destination identifiers and media before making a remote request', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(linkedInPublishingRequest($connection, '123456')))
        ->toThrow(PublishingAdapterException::class, 'canonical person or organization URNs');

    $request = new PublishingAdapterRequest(
        network: 'linkedin',
        destinationExternalId: 'urn:li:person:123456',
        content: 'Text with media',
        media: [['mime_type' => 'image/jpeg', 'size' => 100]],
        scheduledAt: null,
        idempotencyKey: 'media-key',
        correlationId: 'media-correlation',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );

    expect(fn () => $adapter->publish($request))
        ->toThrow(PublishingAdapterException::class, 'media publishing is not implemented');
});

it('normalizes credential lifecycle failures into a publishing authentication error', function () {
    $organization = Organization::factory()->create();
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'linkedin',
        'external_account_id' => 'member-123',
        'credential_reference' => 'vault/linkedin/member-123',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->once()
        ->andThrow(new SocialAuthorizationException('invalid_grant', 'Provider-specific details must not escape.'));
    app()->instance(CredentialResolver::class, $credentials);
    $adapter = app(LinkedInPublishingAdapter::class);
    Http::preventStrayRequests();

    try {
        $adapter->publish(linkedInPublishingRequest($connection));
        test()->fail('Expected LinkedIn authentication failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('authentication_required')
            ->and($exception->getMessage())->not->toContain('Provider-specific details');
    }
});

it('rejects a malformed LinkedIn API version before making a remote request', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    config(['publishing.linkedin.api_version' => 'latest']);
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(linkedInPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, 'YYYYMM format');
});

it('maps authentication failures without leaking provider response bodies', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'forbidden'], 403)]);

    try {
        $adapter->publish(linkedInPublishingRequest($connection));
        test()->fail('Expected LinkedIn authorization failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('authentication_required')
            ->and($exception->getMessage())->not->toContain('forbidden');
    }
});

it('classifies LinkedIn rate limits as retryable and non-ambiguous', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'slow down'], 429)]);

    try {
        $adapter->publish(linkedInPublishingRequest($connection));
        test()->fail('Expected LinkedIn rate-limit failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('rate_limited')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('slow down');
    }
});

it('treats LinkedIn server errors as ambiguous outcomes', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response([], 503)]);

    try {
        $adapter->publish(linkedInPublishingRequest($connection));
        test()->fail('Expected ambiguous LinkedIn server failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('provider_error')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeTrue();
    }
});

it('treats a missing LinkedIn post identifier after acceptance as ambiguous', function () {
    [, $connection, $adapter] = linkedInPublishingAdapterContext();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response([], 201)]);

    try {
        $adapter->publish(linkedInPublishingRequest($connection));
        test()->fail('Expected missing LinkedIn post ID to be ambiguous.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('invalid_adapter_response')
            ->and($exception->ambiguous)->toBeTrue();
    }
});
