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
use App\Services\Publishing\FacebookPublishingAdapter;
use App\Services\Social\ConfiguredOAuth2SocialAuthorizationProvider;
use App\Services\Social\SocialCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** @return array{Organization, IntegrationConnection, FacebookPublishingAdapter} */
function facebookPublishingAdapterContext(array $destinationTokens = ['facebook:123456' => ['access_token' => 'page-access-token', 'expires_at' => null]], array $scopes = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']): array
{
    config(['social_authorization.providers.meta.graph_api_version' => 'v26.0']);

    $organization = Organization::factory()->create();
    $identity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'meta',
        'external_id' => 'meta-user-123',
        'name' => 'Test Meta User',
    ]);
    $credentialReference = app(SocialCredentialStore::class)->store(
        $organization,
        'meta',
        new SocialTokenSet(
            accessToken: 'parent-user-token',
            refreshToken: null,
            accessTokenExpiresAt: null,
            refreshTokenExpiresAt: null,
            scopes: $scopes,
            destinationTokens: $destinationTokens,
        ),
    );
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'social_identity_id' => $identity->id,
        'provider' => 'meta',
        'external_account_id' => $identity->external_id,
        'credential_reference' => $credentialReference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'facebook')
        ->andReturn('parent-user-token');
    app()->instance(CredentialResolver::class, $credentials);

    return [$organization, $connection, app(FacebookPublishingAdapter::class)];
}

function facebookPublishingRequest(IntegrationConnection $connection, string $destination = '123456', string $content = 'A Facebook Page post from CR8OR.', array $media = []): PublishingAdapterRequest
{
    return new PublishingAdapterRequest(
        network: 'facebook',
        destinationExternalId: $destination,
        content: $content,
        media: $media,
        scheduledAt: null,
        idempotencyKey: 'facebook-publication-key',
        correlationId: 'facebook-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
}

it('declares only supported Facebook Page capabilities and stays disabled until verified', function () {
    $adapter = app(FacebookPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('facebook')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.edit'))->toBeFalse()
        ->and($capabilities->supportedAccountTypes)->toBe(['facebook.page'])
        ->and($capabilities->supportedDestinationTypes)->toBe(['facebook.page'])
        ->and($capabilities->supportedMediaTypes)->toBe(['image/jpeg', 'image/png'])
        ->and($capabilities->maxTextLength)->toBe(63206)
        ->and($capabilities->maxMediaItems)->toBe(1)
        ->and($capabilities->maxMediaSizeBytes)->toBe(10_485_760)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->editing)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->deletion)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->analytics)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.facebook.class'))->toBe(FacebookPublishingAdapter::class)
        ->and(config('publishing.adapters.facebook.enabled'))->toBeFalse()
        ->and(config('social_authorization.providers.meta.graph_api_version'))->toBe('v26.0');
});

it('publishes text and link posts only to a numeric Page using the stored Page token', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake([
        'https://graph.facebook.com/v26.0/123456/feed' => Http::response([
            'id' => '123456_987654',
            'permalink_url' => 'https://www.facebook.com/123456/posts/987654',
        ], 200),
    ]);

    $result = $adapter->publish(facebookPublishingRequest($connection, content: 'Read this: https://example.com/article.'));

    expect($result->externalId)->toBe('123456_987654')
        ->and($result->externalUrl)->toBe('https://www.facebook.com/123456/posts/987654')
        ->and($result->status)->toBe('published')
        ->and($result->correlationId)->toBe('facebook-correlation-id')
        ->and($result->idempotencyKey)->toBe('facebook-publication-key')
        ->and($result->payload)->toBe([
            'network' => 'facebook',
            'destination_external_id' => '123456',
            'content_type' => 'link',
            'media_count' => 0,
        ]);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v26.0/123456/feed'
        && $request->hasHeader('Authorization', 'Bearer page-access-token')
        && $request['message'] === 'Read this: https://example.com/article.'
        && $request['link'] === 'https://example.com/article');
});

it('publishes one R2-backed JPEG or PNG through the Page Photos endpoint without exposing the signed URL', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/hero.png')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')->once()
        ->with('assets/hero.png', Mockery::type(DateTimeInterface::class))
        ->andReturn('https://r2.example.test/assets/hero.png?signature=temporary-secret');
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://graph.facebook.com/v26.0/123456/photos' => Http::response([
            'id' => '987654',
            'post_id' => '123456_987654',
        ], 200),
    ]);

    $result = $adapter->publish(facebookPublishingRequest(
        $connection,
        content: 'A picture from CR8OR.',
        media: [[
            'asset_id' => 42,
            'mime_type' => 'image/png',
            'size' => 2048,
            'disk' => 'r2',
            'path' => 'assets/hero.png',
            'metadata' => [],
        ]],
    ));

    expect($result->externalId)->toBe('123456_987654')
        ->and($result->status)->toBe('published')
        ->and(json_encode($result->payload))->not->toContain('temporary-secret')
        ->and(json_encode($result->payload))->not->toContain('page-access-token');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v26.0/123456/photos'
        && $request->hasHeader('Authorization', 'Bearer page-access-token')
        && $request['url'] === 'https://r2.example.test/assets/hero.png?signature=temporary-secret'
        && $request['caption'] === 'A picture from CR8OR.'
        && $request['published'] === 'true');
});

it('rejects profiles, unknown Pages, expired Page tokens and credential-reference mismatches before submission', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(facebookPublishingRequest($connection, 'me')))
        ->toThrow(PublishingAdapterException::class, 'numeric Facebook Page ID');

    expect(fn () => $adapter->publish(facebookPublishingRequest($connection, '999999')))
        ->toThrow(PublishingAdapterException::class, 'No Page access token is stored');

    $wrongReference = new PublishingAdapterRequest(
        network: 'facebook',
        destinationExternalId: '123456',
        content: 'A post',
        media: [],
        scheduledAt: null,
        idempotencyKey: 'wrong-reference-key',
        correlationId: 'wrong-reference-correlation',
        integrationConnectionId: (int) $connection->id,
        credentialReference: 'social:another-credential',
    );
    expect(fn () => $adapter->publish($wrongReference))
        ->toThrow(PublishingAdapterException::class, 'does not match the publication credential reference');

    [, $expiredConnection, $expiredAdapter] = facebookPublishingAdapterContext([
        'facebook:123456' => ['access_token' => 'expired-page-token', 'expires_at' => CarbonImmutable::now()->subMinute()->toIso8601String()],
    ]);
    expect(fn () => $expiredAdapter->publish(facebookPublishingRequest($expiredConnection)))
        ->toThrow(PublishingAdapterException::class, 'Page access token has expired');
});

it('rejects unsupported, oversized, multi-image and unavailable R2 media before Meta submission', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish(facebookPublishingRequest($connection, media: [[
        'mime_type' => 'video/mp4', 'size' => 1024, 'disk' => 'r2', 'path' => 'assets/video.mp4',
    ]])))
        ->toThrow(PublishingAdapterException::class, 'JPEG and PNG images only');

    expect(fn () => $adapter->publish(facebookPublishingRequest($connection, media: [[
        'mime_type' => 'image/jpeg', 'size' => 10_485_761, 'disk' => 'r2', 'path' => 'assets/large.jpg',
    ]])))
        ->toThrow(PublishingAdapterException::class, 'no greater than 10 MiB');

    expect(fn () => $adapter->publish(facebookPublishingRequest($connection, media: [
        ['mime_type' => 'image/jpeg', 'size' => 100, 'disk' => 'r2', 'path' => 'assets/one.jpg'],
        ['mime_type' => 'image/png', 'size' => 100, 'disk' => 'r2', 'path' => 'assets/two.png'],
    ])))->toThrow(PublishingAdapterException::class, 'supports one image');

    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/missing.png')->andReturn(false);
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);
    expect(fn () => $adapter->publish(facebookPublishingRequest($connection, media: [[
        'mime_type' => 'image/png', 'size' => 100, 'disk' => 'r2', 'path' => 'assets/missing.png',
    ]])))
        ->toThrow(PublishingAdapterException::class, 'no longer exists in R2');
});

it('verifies the required Meta permission before publishing when the stored grant has no scope metadata', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext(scopes: []);
    Http::fake([
        'https://graph.facebook.com/v26.0/me/permissions' => Http::response([
            'data' => [
                ['permission' => 'pages_show_list', 'status' => 'granted'],
                ['permission' => 'pages_read_engagement', 'status' => 'granted'],
            ],
        ], 200),
    ]);

    expect(fn () => $adapter->publish(facebookPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, 'has not granted the required Page permission');

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
});

it('looks up only a known post belonging to the selected Page', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake([
        'https://graph.facebook.com/v26.0/123456_987654*' => Http::response([
            'id' => '123456_987654',
            'is_published' => true,
            'permalink_url' => 'https://www.facebook.com/123456/posts/987654',
            'created_time' => '2026-10-12T10:00:00+0000',
        ], 200),
    ]);

    $result = $adapter->lookupStatus(new PublishingRemotePublication(
        network: 'facebook',
        destinationExternalId: '123456',
        externalPublicationId: '123456_987654',
        idempotencyKey: 'facebook-publication-key',
        correlationId: 'facebook-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    ));

    expect($result?->status)->toBe('published')
        ->and($result?->externalUrl)->toBe('https://www.facebook.com/123456/posts/987654')
        ->and($result?->correlationId)->toBe('facebook-correlation-id')
        ->and($result?->idempotencyKey)->toBe('facebook-publication-key');

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/123456_987654')
        && $request->hasHeader('Authorization', 'Bearer page-access-token'));
});

it('returns unknown status for missing posts and rejects identifiers from another Page', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake(['https://graph.facebook.com/v26.0/123456_987654*' => Http::response(['error' => ['message' => 'not found']], 404)]);

    $publication = new PublishingRemotePublication(
        network: 'facebook',
        destinationExternalId: '123456',
        externalPublicationId: '123456_987654',
        idempotencyKey: 'facebook-publication-key',
        correlationId: 'facebook-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );

    expect($adapter->lookupStatus($publication))->toBeNull()
        ->and(fn () => $adapter->lookupStatus(new PublishingRemotePublication(
            network: 'facebook',
            destinationExternalId: '123456',
            externalPublicationId: '999999_987654',
            idempotencyKey: 'facebook-publication-key',
            correlationId: 'facebook-correlation-id',
            integrationConnectionId: (int) $connection->id,
            credentialReference: $connection->credential_reference,
        )))->toThrow(PublishingAdapterException::class, 'belonging to the selected Page');
});

it('classifies permission and rate-limit errors without exposing Meta response messages', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake(['https://graph.facebook.com/v26.0/123456/feed' => Http::response([
        'error' => ['message' => 'private provider details', 'type' => 'OAuthException', 'code' => 200],
    ], 400)]);

    try {
        $adapter->publish(facebookPublishingRequest($connection));
        test()->fail('Expected Meta permission failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('permission_denied')
            ->and($exception->getMessage())->not->toContain('private provider details');
    }
});

it('classifies Meta rate limits as retryable and non-ambiguous', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake(['https://graph.facebook.com/v26.0/123456/feed' => Http::response([
        'error' => ['message' => 'rate details', 'code' => 4],
    ], 400)]);

    try {
        $adapter->publish(facebookPublishingRequest($connection));
        test()->fail('Expected Meta rate limit failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('rate_limited')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('rate details');
    }
});

it('marks Meta server errors after submission as ambiguous', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake(['https://graph.facebook.com/v26.0/123456/feed' => Http::response(['error' => ['message' => 'temporary']], 500)]);

    try {
        $adapter->publish(facebookPublishingRequest($connection));
        test()->fail('Expected ambiguous Meta server failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('provider_unavailable')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeTrue();
    }
});

it('marks a successful Meta response without a valid Page post ID as ambiguous', function () {
    [, $connection, $adapter] = facebookPublishingAdapterContext();
    Http::fake(['https://graph.facebook.com/v26.0/123456/feed' => Http::response(['success' => true], 200)]);

    try {
        $adapter->publish(facebookPublishingRequest($connection));
        test()->fail('Expected a missing Page post ID to be ambiguous.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('invalid_adapter_response')
            ->and($exception->ambiguous)->toBeTrue();
    }
});

it('discovers only Meta Pages whose tasks permit content creation and preserves their Page tokens', function () {
    $providerConfig = config('social_authorization.providers.meta');
    $providerConfig['enabled'] = true;
    $provider = new ConfiguredOAuth2SocialAuthorizationProvider('meta', $providerConfig);

    Http::fake([
        'https://graph.facebook.com/v26.0/me/accounts*' => Http::response([
            'data' => [
                ['id' => '123456', 'name' => 'Allowed Page', 'link' => 'https://www.facebook.com/allowed', 'access_token' => 'page-token-allowed', 'tasks' => ['CREATE_CONTENT', 'MODERATE']],
                ['id' => '234567', 'name' => 'Moderator Only Page', 'link' => 'https://www.facebook.com/moderator', 'access_token' => 'page-token-moderator', 'tasks' => ['MODERATE']],
                ['id' => '345678', 'name' => 'No Tasks Page', 'link' => 'https://www.facebook.com/no-tasks', 'access_token' => 'page-token-none'],
                ['id' => '456789', 'name' => 'Missing Token Page', 'link' => 'https://www.facebook.com/missing-token', 'tasks' => ['CREATE_CONTENT']],
            ],
        ]),
        'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => 'meta-user-123', 'name' => 'Test Meta User']),
    ]);

    $result = $provider->discover(new SocialTokenSet('parent-user-token', null, null, null, ['pages_show_list']), 'facebook');

    expect($result->destinations)->toHaveCount(1)
        ->and($result->destinations[0]->externalId)->toBe('123456')
        ->and($result->destinations[0]->name)->toBe('Allowed Page')
        ->and($result->destinations[0]->credentialToken)->toBe('page-token-allowed');

    expect(config('social_authorization.providers.meta.destinations.0.url'))->toContain('tasks');
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/me/accounts'));
});
