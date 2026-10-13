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
use App\Services\Publishing\BlueskyPublishingAdapter;
use App\Services\Social\AtProtoDpopProof;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** @return array{Organization, IntegrationConnection, BlueskyPublishingAdapter, string} */
function blueskyPublishingContext(): array
{
    $did = 'did:plc:abcdefghijklmnopqrstuvwx';
    $organization = Organization::factory()->create();
    $identity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'bluesky',
        'external_id' => $did,
        'name' => 'test.bsky.social',
    ]);
    $keyPair = app(AtProtoDpopProof::class)->generateKeyPair();
    $reference = app(SocialCredentialStore::class)->store($organization, 'bluesky', new SocialTokenSet(
        accessToken: 'bluesky-access-token',
        refreshToken: 'bluesky-refresh-token',
        accessTokenExpiresAt: null,
        refreshTokenExpiresAt: null,
        scopes: ['atproto', 'repo:app.bsky.feed.post?action=create', 'blob:*/*'],
        tokenType: 'DPoP',
        providerContext: [
            'expected_did' => $did,
            'expected_handle' => 'test.bsky.social',
            'pds_origin' => 'https://pds.example.test',
            'issuer' => 'https://auth.example.test',
            'token_endpoint' => 'https://auth.example.test/oauth/token',
            'private_key_pem' => $keyPair['private_key_pem'],
            'jwk' => $keyPair['jwk'],
            'dpop_thumbprint' => $keyPair['thumbprint'],
            'dpop_nonces' => [],
        ],
    ));
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'social_identity_id' => $identity->id,
        'provider' => 'bluesky',
        'external_account_id' => $did,
        'credential_reference' => $reference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'bsky')
        ->andReturn('bluesky-access-token');
    app()->instance(CredentialResolver::class, $credentials);
    config([
        'social_authorization.providers.bluesky.driver' => 'atproto_oauth',
        'social_authorization.providers.bluesky.enabled' => true,
        'social_authorization.providers.bluesky.client_id' => 'https://cr8or.example.test/oauth-client-metadata.json',
        'social_authorization.providers.bluesky.redirect_uri' => 'https://cr8or.example.test/social/authorization/callback/bluesky',
    ]);

    return [$organization, $connection, app(BlueskyPublishingAdapter::class), $did];
}

function blueskyPublishingRequest(IntegrationConnection $connection, string $did, array $overrides = []): PublishingAdapterRequest
{
    return new PublishingAdapterRequest(
        network: $overrides['network'] ?? 'bsky',
        destinationExternalId: $overrides['destination'] ?? $did,
        content: $overrides['content'] ?? 'A post from CR8OR',
        media: $overrides['media'] ?? [],
        scheduledAt: $overrides['scheduled_at'] ?? null,
        idempotencyKey: $overrides['idempotency_key'] ?? 'bluesky-idempotency-key',
        correlationId: 'bluesky-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    );
}

it('declares text and image publishing and keeps the adapter disabled by default', function () {
    $adapter = app(BlueskyPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('bsky')
        ->and($adapter->providerKey())->toBe('bluesky')
        ->and($capabilities->supportedMediaTypes)->toBe(['image/jpeg', 'image/png'])
        ->and($capabilities->maxMediaItems)->toBe(4)
        ->and($capabilities->maxMediaSizeBytes)->toBe(2_000_000)
        ->and($capabilities->maxTextLength)->toBe(300)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.bsky.class'))->toBe(BlueskyPublishingAdapter::class)
        ->and(config('publishing.adapters.bsky.enabled'))->toBeFalse();
});

it('rejects unsupported requests before making any network calls', function (array $overrides, string $failureCode) {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    Http::fake();

    try {
        $adapter->publish(blueskyPublishingRequest($connection, $did, $overrides));
        test()->fail('Expected a publishing validation error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe($failureCode);
    }

    Http::assertNothingSent();
})->with([
    'wrong network' => [['network' => 'x'], 'network_mismatch'],
    'scheduled request' => [['scheduled_at' => '2026-10-14T10:00:00Z'], 'scheduling_unsupported'],
    'video not supported' => [['media' => [['mime_type' => 'video/mp4']]], 'unsupported_media'],
    'image too large' => [['media' => [['mime_type' => 'image/jpeg', 'size' => 2_000_001, 'disk' => 'r2', 'path' => 'assets/image.jpg']]], 'media_size_exceeded'],
    'empty text' => [['content' => '   '], 'empty_content'],
    'text over grapheme limit' => [['content' => str_repeat('a', 301)], 'content_too_long'],
    'wrong destination DID' => [['destination' => 'did:plc:zyxwvutsrqponmlkjihgfedc'], 'invalid_destination'],
]);

it('creates a deterministic post record with UTF-8 byte-indexed link and hashtag facets', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound'], 400, ['DPoP-Nonce' => 'pds-nonce']),
        'https://pds.example.test/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:zyxwvutsrqponmlkjihgfedcba'], 200, ['DPoP-Nonce' => 'pds-nonce']),
        'https://pds.example.test/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => "at://{$did}/app.bsky.feed.post/{$rkey}",
            'cid' => 'bafyrecordcid',
        ], 200, ['DPoP-Nonce' => 'pds-nonce']),
    ]);

    $result = $adapter->publish(blueskyPublishingRequest($connection, $did, ['content' => 'Café launch https://example.com @bob.bsky.social #build']));

    expect($result->externalId)->toBe("at://{$did}/app.bsky.feed.post/{$rkey}")
        ->and($result->externalUrl)->toBe("https://bsky.app/profile/{$did}/post/{$rkey}")
        ->and($result->status)->toBe('published')
        ->and($result->payload['cid'])->toBe('bafyrecordcid');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/com.atproto.repo.createRecord')
        && $request['repo'] === $did
        && $request['rkey'] === $rkey
        && $request['record']['text'] === 'Café launch https://example.com @bob.bsky.social #build'
        && count($request['record']['facets'] ?? []) === 3
        && $request['record']['facets'][0]['features'][0]['$type'] === 'app.bsky.richtext.facet#link'
        && $request['record']['facets'][0]['index']['byteStart'] === strlen('Café launch ')
        && $request['record']['facets'][1]['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'
        && $request['record']['facets'][1]['features'][0]['did'] === 'did:plc:zyxwvutsrqponmlkjihgfedcba'
        && $request['record']['facets'][2]['features'][0]['$type'] === 'app.bsky.richtext.facet#tag'
        && $request->hasHeader('Authorization', 'DPoP bluesky-access-token')
        && $request->hasHeader('DPoP'));
});

it('reconciles an already-created deterministic record instead of submitting a duplicate', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response([
            'uri' => "at://{$did}/app.bsky.feed.post/{$rkey}",
            'cid' => 'bafyexistingcid',
            'value' => ['text' => 'A post from CR8OR'],
        ], 200, ['DPoP-Nonce' => 'pds-nonce']),
    ]);

    $result = $adapter->publish(blueskyPublishingRequest($connection, $did));

    expect($result->payload['cid'])->toBe('bafyexistingcid');
    Http::assertSentCount(1);
});

it('rejects reuse of an idempotency key for different content', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response([
            'uri' => "at://{$did}/app.bsky.feed.post/{$rkey}",
            'cid' => 'bafyexistingcid',
            'value' => ['text' => 'Different content'],
        ], 200, ['DPoP-Nonce' => 'pds-nonce']),
    ]);

    expect(fn () => $adapter->publish(blueskyPublishingRequest($connection, $did)))
        ->toThrow(PublishingAdapterException::class, 'already used for different content');
    Http::assertSentCount(1);
});

it('maps rate limits and ambiguous create failures without blind resubmission', function (int $status, string $failureCode, bool $ambiguous) {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound'], 400, ['DPoP-Nonce' => 'pds-nonce']),
        'https://pds.example.test/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'ProviderFailure'], $status, ['DPoP-Nonce' => 'pds-nonce']),
    ]);

    try {
        $adapter->publish(blueskyPublishingRequest($connection, $did));
        test()->fail('Expected a publishing provider error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe($failureCode)
            ->and($exception->ambiguous)->toBe($ambiguous);
    }
})->with([
    'rate limited' => [429, 'rate_limited', false],
    'server error after submission' => [503, 'provider_unavailable', true],
]);

it('reconciles a known Bluesky record through the status lookup contract', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    $uri = "at://{$did}/app.bsky.feed.post/{$rkey}";
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response([
            'uri' => $uri,
            'cid' => 'bafylookupcid',
            'value' => ['text' => 'A post from CR8OR'],
        ], 200, ['DPoP-Nonce' => 'pds-lookup-nonce']),
    ]);

    $result = $adapter->lookupStatus(new PublishingRemotePublication(
        network: 'bsky',
        destinationExternalId: $did,
        externalPublicationId: $uri,
        idempotencyKey: 'bluesky-idempotency-key',
        correlationId: 'bluesky-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    ));

    expect($result?->status)->toBe('published')
        ->and($result?->externalId)->toBe($uri)
        ->and($result?->externalUrl)->toBe("https://bsky.app/profile/{$did}/post/{$rkey}")
        ->and($result?->payload['cid'])->toBe('bafylookupcid');
});

it('returns unknown status for a record that cannot be found', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound'], 400, ['DPoP-Nonce' => 'pds-missing-nonce']),
    ]);

    $result = $adapter->lookupStatus(new PublishingRemotePublication(
        network: 'bsky',
        destinationExternalId: $did,
        externalPublicationId: "at://{$did}/app.bsky.feed.post/{$rkey}",
        idempotencyKey: 'bluesky-idempotency-key',
        correlationId: 'bluesky-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    ));

    expect($result)->toBeNull();
});

function blueskyTestJpegBytes(): string
{
    $app1 = "\xFF\xE1\x00\x08Exif\x00\x00";
    $sof = "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00";
    $scan = "\xFF\xDA\x00\x02scan-data\xFF\xD9";

    return "\xFF\xD8".$app1.$sof.$scan;
}

it('strips JPEG metadata, uploads a blob and embeds its reference in the post', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    Storage::fake('r2');
    $image = blueskyTestJpegBytes();
    Storage::disk('r2')->put('assets/bluesky.jpg', $image);
    $sanitized = substr($image, 0, 2).substr($image, 12);
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.uploadBlob' => Http::response(['blob' => [
            '$type' => 'blob', 'ref' => ['$link' => 'bafkimagecid'], 'mimeType' => 'image/jpeg', 'size' => strlen($sanitized),
        ]], 200, ['DPoP-Nonce' => 'blob-nonce']),
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound'], 400, ['DPoP-Nonce' => 'record-nonce']),
        'https://pds.example.test/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => "at://{$did}/app.bsky.feed.post/{$rkey}", 'cid' => 'bafyimagepostcid',
        ], 200, ['DPoP-Nonce' => 'create-nonce']),
    ]);

    $result = $adapter->publish(blueskyPublishingRequest($connection, $did, ['media' => [[
        'asset_id' => 21, 'mime_type' => 'image/jpeg', 'size' => strlen($image), 'disk' => 'r2', 'path' => 'assets/bluesky.jpg',
        'dimensions' => ['width' => 1200, 'height' => 800], 'metadata' => ['alt_text' => 'A test image'],
    ]]]));

    expect($result->payload['cid'])->toBe('bafyimagepostcid');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/com.atproto.repo.uploadBlob')
        && $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'image/jpeg')
        && $request->body() === $sanitized
        && ! str_contains($request->body(), 'Exif')
        && $request->hasHeader('Authorization', 'DPoP bluesky-access-token'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/com.atproto.repo.createRecord')
        && $request['record']['embed']['$type'] === 'app.bsky.embed.images'
        && $request['record']['embed']['images'][0]['alt'] === 'A test image'
        && $request['record']['embed']['images'][0]['image']['ref']['$link'] === 'bafkimagecid'
        && $request['record']['embed']['images'][0]['aspectRatio'] === ['width' => 1200, 'height' => 800]);
});

it('stops before record creation when image blob upload is rate limited', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    Storage::fake('r2');
    $image = blueskyTestJpegBytes();
    Storage::disk('r2')->put('assets/bluesky.jpg', $image);
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'RateLimitExceeded'], 429, ['DPoP-Nonce' => 'blob-nonce']),
    ]);

    expect(fn () => $adapter->publish(blueskyPublishingRequest($connection, $did, ['media' => [[
        'mime_type' => 'image/jpeg', 'size' => strlen($image), 'disk' => 'r2', 'path' => 'assets/bluesky.jpg',
    ]]])))->toThrow(PublishingAdapterException::class, 'blob upload');

    Http::assertSentCount(1);
});

it('rejects a credential reference from another organization before network access', function () {
    [$organization, $connection, $adapter, $did] = blueskyPublishingContext();
    $foreignOrganization = Organization::factory()->create();
    $foreignReference = app(SocialCredentialStore::class)->store($foreignOrganization, 'bluesky', new SocialTokenSet(
        accessToken: 'foreign-access-token', refreshToken: 'foreign-refresh-token', accessTokenExpiresAt: null,
        refreshTokenExpiresAt: null, scopes: ['atproto'],
    ));
    Http::fake();
    $request = new PublishingAdapterRequest(
        network: 'bsky', destinationExternalId: $did, content: 'A post from CR8OR', media: [], scheduledAt: null,
        idempotencyKey: 'foreign-credential-test', correlationId: 'bluesky-correlation-id',
        integrationConnectionId: (int) $connection->id, credentialReference: $foreignReference,
    );

    expect(fn () => $adapter->publish($request))
        ->toThrow(PublishingAdapterException::class, 'does not match');
    Http::assertNothingSent();
});

it('marks a create-record timeout ambiguous and never retries the write in place', function () {
    [, $connection, $adapter, $did] = blueskyPublishingContext();
    $rkey = 'cr8or'.substr(hash('sha256', 'bluesky-idempotency-key'), 0, 24);
    $createAttempts = 0;
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound'], 400, ['DPoP-Nonce' => 'record-nonce']),
        'https://pds.example.test/xrpc/com.atproto.repo.createRecord' => function () use (&$createAttempts) {
            $createAttempts++;
            throw new Illuminate\Http\Client\ConnectionException('connection timed out');
        },
    ]);

    try {
        $adapter->publish(blueskyPublishingRequest($connection, $did));
        test()->fail('Expected an ambiguous publishing outcome.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('timeout')
            ->and($exception->ambiguous)->toBeTrue()
            ->and($exception->retryable)->toBeTrue();
    }

    expect($createAttempts)->toBe(1);
});
