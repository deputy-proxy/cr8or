<?php

use App\Contracts\CredentialResolver;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingRemotePublication;
use App\Data\Social\SocialTokenSet;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SocialIdentity;
use App\Services\Publishing\InstagramPublishingAdapter;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, array{access_token: string, expires_at: string|null}>  $destinationTokens
 * @param  list<string>  $scopes
 * @return array{Organization, IntegrationConnection, InstagramPublishingAdapter}
 */
function instagramPublishingAdapterContext(
    array $destinationTokens = ['instagram:17841400000000000' => ['access_token' => 'instagram-destination-token', 'expires_at' => null]],
    array $scopes = ['instagram_business_basic', 'instagram_business_content_publish'],
): array {
    config([
        'social_authorization.providers.instagram.graph_api_version' => 'v26.0',
        'publishing.instagram.container_poll_attempts' => 2,
        'publishing.instagram.container_poll_interval_ms' => 0,
    ]);

    $organization = Organization::factory()->create();
    $identity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'instagram',
        'external_id' => '17841400000000000',
        'name' => 'Test Meta User',
    ]);
    $credentialReference = app(SocialCredentialStore::class)->store(
        $organization,
        'instagram',
        new SocialTokenSet(
            accessToken: 'parent-user-token',
            refreshToken: null,
            accessTokenExpiresAt: null,
            refreshTokenExpiresAt: null,
            scopes: $scopes,
        ),
    );
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'social_identity_id' => $identity->id,
        'provider' => 'instagram',
        'external_account_id' => $identity->external_id,
        'credential_reference' => $credentialReference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'instagram')
        ->andReturn('parent-user-token');
    app()->instance(CredentialResolver::class, $credentials);

    return [$organization, $connection, app(InstagramPublishingAdapter::class)];
}

/** @param list<array<string, mixed>> $media */
function instagramPublishingRequest(
    IntegrationConnection $connection,
    string $destination = '17841400000000000',
    string $content = 'A photo from CR8OR.',
    array $media = [[
        'asset_id' => 42,
        'mime_type' => 'image/jpeg',
        'size' => 2048,
        'disk' => 'r2',
        'path' => 'assets/instagram.jpg',
        'dimensions' => ['width' => 1080, 'height' => 1350],
        'metadata' => ['alt_text' => 'A red bicycle by the sea.'],
    ]],
): PublishingAdapterRequest {
    return new PublishingAdapterRequest(
        network: 'instagram',
        destinationExternalId: $destination,
        content: $content,
        media: $media,
        scheduledAt: null,
        idempotencyKey: 'instagram-publication-key',
        correlationId: 'instagram-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
}

it('declares a narrow, disabled Instagram professional-account capability set', function () {
    $adapter = app(InstagramPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('instagram')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.edit'))->toBeFalse()
        ->and($capabilities->supportedAccountTypes)->toBe(['instagram.professional'])
        ->and($capabilities->supportedDestinationTypes)->toBe(['instagram.professional'])
        ->and($capabilities->supportedMediaTypes)->toBe(['image/jpeg'])
        ->and($capabilities->maxTextLength)->toBe(2200)
        ->and($capabilities->maxMediaItems)->toBe(1)
        ->and($capabilities->maxMediaSizeBytes)->toBe(8_388_608)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->editing)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->deletion)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->analytics)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.instagram.class'))->toBe(InstagramPublishingAdapter::class)
        ->and(config('publishing.adapters.instagram.enabled'))->toBeFalse();
});

it('publishes a single R2-backed JPEG using the Instagram User token and container lifecycle', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/instagram.jpg')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')->once()
        ->with('assets/instagram.jpg', Mockery::type(DateTimeInterface::class))
        ->andReturn('https://r2.example.test/assets/instagram.jpg?signature=temporary-secret');
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://graph.instagram.com/v26.0/17841400000000000/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]], 200),
        'https://graph.instagram.com/v26.0/17841400000000000/media' => Http::response(['id' => '180000000000001'], 200),
        'https://graph.instagram.com/v26.0/180000000000001?*' => Http::response(['id' => '180000000000001', 'status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v26.0/17841400000000000/media_publish' => Http::response(['id' => '180000000000002'], 200),
    ]);

    $result = $adapter->publish(instagramPublishingRequest($connection));

    expect($result->externalId)->toBe('180000000000002')
        ->and($result->status)->toBe('published')
        ->and($result->correlationId)->toBe('instagram-correlation-id')
        ->and($result->idempotencyKey)->toBe('instagram-publication-key')
        ->and($result->payload)->toBe([
            'network' => 'instagram',
            'destination_external_id' => '17841400000000000',
            'content_type' => 'image',
            'media_count' => 1,
            'container_id' => '180000000000001',
        ])
        ->and(json_encode($result->payload))->not->toContain('temporary-secret')
        ->and(json_encode($result->payload))->not->toContain('parent-user-token');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.instagram.com/v26.0/17841400000000000/media'
        && $request->hasHeader('Authorization', 'Bearer parent-user-token')
        && $request['image_url'] === 'https://r2.example.test/assets/instagram.jpg?signature=temporary-secret'
        && $request['caption'] === 'A photo from CR8OR.'
        && $request['alt_text'] === 'A red bicycle by the sea.');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.instagram.com/v26.0/17841400000000000/media_publish'
        && $request['creation_id'] === '180000000000001');
});

it('rejects Facebook Page IDs, missing images, unsupported formats, invalid dimensions and oversized captions before network submission', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection, 'facebook-page-123')))
        ->toThrow(PublishingAdapterException::class, 'numeric Instagram professional-account ID');

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection, media: [])))
        ->toThrow(PublishingAdapterException::class, 'exactly one JPEG image');

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection, media: [[
        'mime_type' => 'image/png', 'size' => 1024, 'disk' => 'r2', 'path' => 'assets/photo.png',
        'metadata' => ['width' => 1080, 'height' => 1350],
    ]])))->toThrow(PublishingAdapterException::class, 'JPEG images only');

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection, media: [[
        'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'r2', 'path' => 'assets/photo.jpg',
        'metadata' => ['width' => 1080, 'height' => 1920],
    ]])))->toThrow(PublishingAdapterException::class, 'aspect ratio');

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection, content: str_repeat('x', 2201))))
        ->toThrow(PublishingAdapterException::class, '2,200-character limit');
});

it('requires a matching active Instagram Login connection', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, 'Instagram connection is unavailable');
});

it('stops before publication when Meta reports failed or unfinished media processing', function (string $status, string $expectedMessage) {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/instagram.jpg')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')->once()
        ->with('assets/instagram.jpg', Mockery::type(DateTimeInterface::class))
        ->andReturn('https://r2.example.test/assets/instagram.jpg');
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://graph.instagram.com/v26.0/17841400000000000/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]], 200),
        'https://graph.instagram.com/v26.0/17841400000000000/media' => Http::response(['id' => '180000000000001'], 200),
        'https://graph.instagram.com/v26.0/180000000000001?*' => Http::response(['id' => '180000000000001', 'status_code' => $status], 200),
    ]);

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, $expectedMessage);

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://graph.instagram.com/v26.0/17841400000000000/media_publish');
})->with([
    ['ERROR', 'could not process the Instagram media container'],
    ['IN_PROGRESS', 'did not finish within the configured polling window'],
]);

it('classifies an ambiguous publish timeout without permitting blind retry', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/instagram.jpg')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')->once()
        ->with('assets/instagram.jpg', Mockery::type(DateTimeInterface::class))
        ->andReturn('https://r2.example.test/assets/instagram.jpg');
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://graph.instagram.com/v26.0/17841400000000000/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]], 200),
        'https://graph.instagram.com/v26.0/17841400000000000/media' => Http::response(['id' => '180000000000001'], 200),
        'https://graph.instagram.com/v26.0/180000000000001?*' => Http::response(['id' => '180000000000001', 'status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v26.0/17841400000000000/media_publish' => Http::failedConnection(),
    ]);

    try {
        $adapter->publish(instagramPublishingRequest($connection));
        throw new RuntimeException('Expected an ambiguous publishing timeout.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('timeout')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeTrue();
    }
});

it('reconciles a known Instagram media ID without inventing a permalink', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    Http::fake([
        'https://graph.instagram.com/v26.0/180000000000002?*' => Http::response([
            'id' => '180000000000002',
            'media_type' => 'IMAGE',
            'permalink' => 'https://www.instagram.com/p/abc123/',
            'timestamp' => '2026-10-12T10:00:00+0000',
        ], 200),
    ]);

    $result = $adapter->lookupStatus(new PublishingRemotePublication(
        network: 'instagram',
        destinationExternalId: '17841400000000000',
        externalPublicationId: '180000000000002',
        idempotencyKey: 'instagram-publication-key',
        correlationId: 'instagram-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    ));

    expect($result?->externalId)->toBe('180000000000002')
        ->and($result?->externalUrl)->toBe('https://www.instagram.com/p/abc123/')
        ->and($result?->status)->toBe('published');
});

it('registers through the network adapter registry and remains disabled by default', function () {
    expect(fn () => app(\App\Services\PublishingAdapterRegistry::class)->resolve('instagram'))
        ->toThrow(PublishingAdapterException::class, 'not enabled');

    config(['publishing.adapters.instagram.enabled' => true]);

    $resolution = app(\App\Services\PublishingAdapterRegistry::class)->resolve('instagram');

    expect($resolution->adapter)->toBeInstanceOf(InstagramPublishingAdapter::class)
        ->and($resolution->adapterKey)->toBe('instagram')
        ->and($resolution->capabilities->supportedDestinationTypes)->toBe(['instagram.professional']);
});

it('blocks submission when the Instagram content-publishing quota is exhausted', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();

    Http::fake([
        'https://graph.instagram.com/v26.0/17841400000000000/content_publishing_limit*' => Http::response([
            'data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]],
        ], 200),
    ]);

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, 'reached its current content-publishing quota');

    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/media'));
});

it('maps Meta rate-limit responses to a retryable non-ambiguous failure', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/instagram.jpg')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')->once()
        ->with('assets/instagram.jpg', Mockery::type(DateTimeInterface::class))
        ->andReturn('https://r2.example.test/assets/instagram.jpg');
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);
    Http::fake([
        'https://graph.instagram.com/v26.0/17841400000000000/content_publishing_limit*' => Http::response([
            'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]],
        ], 200),
        'https://graph.instagram.com/v26.0/17841400000000000/media' => Http::response([
            'error' => ['message' => 'Rate limit reached', 'code' => 4],
        ], 429),
    ]);

    try {
        $adapter->publish(instagramPublishingRequest($connection));
        throw new RuntimeException('Expected a rate-limit failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('rate_limited')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeFalse();
    }

    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/media_publish'));
});

it('rejects malformed or excessive image alt text before creating a container', function () {
    [, $connection, $adapter] = instagramPublishingAdapterContext();
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(instagramPublishingRequest($connection, media: [[
        'mime_type' => 'image/jpeg',
        'size' => 2048,
        'disk' => 'r2',
        'path' => 'assets/instagram.jpg',
        'dimensions' => ['width' => 1080, 'height' => 1350],
        'metadata' => ['alt_text' => str_repeat('x', 1001)],
    ]])))->toThrow(PublishingAdapterException::class, 'at most 1,000 characters');
});