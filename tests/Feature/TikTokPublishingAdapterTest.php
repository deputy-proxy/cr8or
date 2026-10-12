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
use App\Services\Publishing\TikTokPublishingAdapter;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function tikTokPublishingContext(array $scopes = ['user.info.basic', 'video.publish']): array
{
    config(['publishing.tiktok.upload_timeout' => 120]);

    $organization = Organization::factory()->create();
    $identity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'tiktok',
        'external_id' => 'open-id-123',
        'name' => 'Test TikTok Creator',
    ]);
    $reference = app(SocialCredentialStore::class)->store(
        $organization,
        'tiktok',
        new SocialTokenSet(
            accessToken: 'tiktok-access-token',
            refreshToken: 'tiktok-refresh-token',
            accessTokenExpiresAt: null,
            refreshTokenExpiresAt: null,
            scopes: $scopes,
        ),
    );
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'social_identity_id' => $identity->id,
        'provider' => 'tiktok',
        'external_account_id' => 'open-id-123',
        'credential_reference' => $reference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'tiktok')
        ->andReturn('tiktok-access-token');
    app()->instance(CredentialResolver::class, $credentials);

    return [$organization, $connection, app(TikTokPublishingAdapter::class)];
}

/** @return list<array<string, mixed>> */
function tikTokVideoMedia(array $overrides = []): array
{
    return [array_replace([
        'asset_id' => 42,
        'mime_type' => 'video/mp4',
        'size' => 1024,
        'disk' => 'r2',
        'path' => 'assets/tiktok.mp4',
        'dimensions' => ['width' => 1080, 'height' => 1920],
        'duration_seconds' => 15,
        'codec' => 'h264',
        'frame_rate' => 30,
        'metadata' => [],
    ], $overrides)];
}

function tikTokSettings(array $overrides = []): array
{
    return array_replace([
        'consent_to_publish' => true,
        'privacy_level' => 'SELF_ONLY',
        'disable_comment' => false,
        'disable_duet' => false,
        'disable_stitch' => false,
        'brand_content_toggle' => false,
    ], $overrides);
}

function tikTokPublishingRequest(
    IntegrationConnection $connection,
    array $settings = [],
    array $media = [],
    string $content = 'A video from CR8OR.',
): PublishingAdapterRequest {
    return new PublishingAdapterRequest(
        network: 'tiktok',
        destinationExternalId: 'open-id-123',
        content: $content,
        media: $media === [] ? tikTokVideoMedia() : $media,
        scheduledAt: null,
        idempotencyKey: 'tiktok-publication-key',
        correlationId: 'tiktok-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
        settings: $settings === [] ? tikTokSettings() : $settings,
    );
}

function tikTokCreatorInfoResponse(int $maxDuration = 180): array
{
    return [
        'data' => [
            'creator_username' => 'testcreator',
            'privacy_level_options' => ['SELF_ONLY', 'PUBLIC_TO_EVERYONE'],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => $maxDuration,
        ],
        'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'test-log'],
    ];
}

function tikTokOkResponse(array $data): array
{
    return ['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'test-log']];
}

function tikTokVideoStream(int $size = 1024)
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, str_repeat('v', $size));
    rewind($stream);

    return $stream;
}

it('declares a narrow disabled TikTok direct-video publishing capability set', function () {
    $adapter = app(TikTokPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('tiktok')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->providerKey())->toBe('tiktok')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.edit'))->toBeFalse()
        ->and($capabilities->supportedAccountTypes)->toBe(['tiktok.creator'])
        ->and($capabilities->supportedDestinationTypes)->toBe(['tiktok.creator'])
        ->and($capabilities->supportedMediaTypes)->toBe(['video/mp4', 'video/quicktime', 'video/webm'])
        ->and($capabilities->maxTextLength)->toBe(2200)
        ->and($capabilities->maxMediaItems)->toBe(1)
        ->and($capabilities->maxMediaSizeBytes)->toBe(4_294_967_296)
        ->and($capabilities->maxVideoDurationSeconds)->toBe(600)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->editing)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->deletion)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->analytics)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.tiktok.class'))->toBe(TikTokPublishingAdapter::class)
        ->and(config('publishing.adapters.tiktok.enabled'))->toBeFalse();
});

it('queries creator settings, initializes direct post, uploads the R2 video, and returns the publish id', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    $stream = tikTokVideoStream();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/tiktok.mp4')->andReturn(true);
    $disk->shouldReceive('readStream')->once()->with('assets/tiktok.mp4')->andReturn($stream);
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(tikTokOkResponse([
            'publish_id' => 'v_pub_file~v2.123456789',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=123&upload_token=temporary-secret',
        ]), 200),
        'https://open-upload.tiktokapis.com/*' => Http::response([], 201),
    ]);

    $result = $adapter->publish(tikTokPublishingRequest($connection));

    expect($result->externalId)->toBe('v_pub_file~v2.123456789')
        ->and($result->status)->toBe('pending')
        ->and($result->payload['publication_mode'])->toBe('direct_post')
        ->and($result->payload)->not->toHaveKey('upload_url')
        ->and($result->payload)->not->toHaveKey('access_token');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/post/publish/video/init/'
        && $request['post_info']['privacy_level'] === 'SELF_ONLY'
        && $request['post_info']['disable_comment'] === false
        && $request['post_info']['brand_content_toggle'] === false
        && $request['source_info']['source'] === 'FILE_UPLOAD'
        && $request['source_info']['video_size'] === 1024
        && $request['source_info']['total_chunk_count'] === 1
    );
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://open-upload.tiktokapis.com/video/')
        && $request->method() === 'PUT'
        && $request->hasHeader('Content-Range', 'bytes 0-1023/1024')
        && $request->body() === str_repeat('v', 1024)
        && ! $request->hasHeader('Authorization')
    );
});

it('requires explicit creator consent and selected publishing settings before any remote request', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake();

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection, ['consent_to_publish' => false])))
        ->toThrow(PublishingAdapterException::class, 'explicit creator consent');

    Http::assertNothingSent();
});

it('rejects unavailable privacy settings before initializing a post', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
    ]);

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection, tikTokSettings(['privacy_level' => 'PUBLIC_TO_FOLLOWERS']))))
        ->toThrow(PublishingAdapterException::class, 'not available');

    Http::assertSentCount(1);
});

it('rejects creator interaction settings that conflict with current account restrictions', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    $creatorInfo = tikTokCreatorInfoResponse();
    $creatorInfo['data']['duet_disabled'] = true;
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response($creatorInfo, 200),
    ]);

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection, tikTokSettings(['disable_duet' => false]))))
        ->toThrow(PublishingAdapterException::class, 'do not permit enabling [duet]');

    Http::assertSentCount(1);
});

it('rejects unsupported codecs, missing frame rate, invalid dimensions, and videos outside the API limits before upload', function (array $media, string $message) {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake();

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection, tikTokSettings(), $media)))
        ->toThrow(PublishingAdapterException::class, $message);

    Http::assertNothingSent();
})->with([
    'unsupported codec' => [tikTokVideoMedia(['codec' => 'mpeg2']), 'codec metadata'],
    'missing frame rate' => [tikTokVideoMedia(['frame_rate' => null]), 'frame rate'],
    'dimensions too small' => [tikTokVideoMedia(['dimensions' => ['width' => 320, 'height' => 1920]]), 'dimensions'],
    'video too large' => [tikTokVideoMedia(['size' => 4_294_967_297]), '4 GiB'],
    'too many media items' => [[...tikTokVideoMedia(), ...tikTokVideoMedia()], 'exactly one video'],
]);

it('rejects videos longer than the creator account currently permits', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(10), 200),
    ]);

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection, tikTokSettings(), tikTokVideoMedia(['duration_seconds' => 15]))))
        ->toThrow(PublishingAdapterException::class, 'maximum post duration');

    Http::assertSentCount(1);
});

it('fails closed when the authorization grant lacks video.publish', function () {
    [, $connection, $adapter] = tikTokPublishingContext(['user.info.basic', 'video.upload']);
    Http::fake();

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, 'lacks the required [video.publish] scope');

    Http::assertNothingSent();
});

it('marks upload failures ambiguous so a new publication cannot blindly create a duplicate', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/tiktok.mp4')->andReturn(true);
    $disk->shouldReceive('readStream')->once()->with('assets/tiktok.mp4')->andReturn(tikTokVideoStream());
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(tikTokOkResponse([
            'publish_id' => 'v_pub_file~v2.123456789',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=123&upload_token=temporary-secret',
        ]), 200),
        'https://open-upload.tiktokapis.com/*' => Http::response(['error' => 'temporary'], 500),
    ]);

    try {
        $adapter->publish(tikTokPublishingRequest($connection));
        test()->fail('An incomplete upload must not be treated as accepted.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('upload_incomplete')
            ->and($exception->ambiguous)->toBeTrue()
            ->and($exception->retryable)->toBeTrue();
    }

    Http::assertSentCount(4);
});

it('uses TikTok upload progress to avoid resending a chunk already accepted after a server error', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/tiktok.mp4')->andReturn(true);
    $disk->shouldReceive('readStream')->once()->with('assets/tiktok.mp4')->andReturn(tikTokVideoStream());
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(tikTokOkResponse([
            'publish_id' => 'v_pub_file~v2.123456789',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=123&upload_token=temporary-secret',
        ]), 200),
        'https://open-upload.tiktokapis.com/*' => Http::response(['error' => 'temporary'], 500),
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tikTokOkResponse([
            'status' => 'PROCESSING_UPLOAD', 'uploaded_bytes' => 1024,
        ]), 200),
    ]);

    $result = $adapter->publish(tikTokPublishingRequest($connection));

    expect($result->externalId)->toBe('v_pub_file~v2.123456789')
        ->and($result->status)->toBe('pending');
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_starts_with($request->url(), 'https://open-upload.tiktokapis.com/'));
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT'))->toHaveCount(1);
});

it('classifies app review rejection separately and never retries it as an ambiguous result', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
            'error' => ['code' => 'unaudited_client_can_only_post_to_private_accounts', 'message' => 'App audit required'],
        ], 403),
    ]);

    try {
        $adapter->publish(tikTokPublishingRequest($connection, tikTokSettings(['privacy_level' => 'PUBLIC_TO_EVERYONE'])));
        test()->fail('Unaudited public posting must be rejected.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('app_review_required')
            ->and($exception->ambiguous)->toBeFalse();
    }
});

it('classifies TikTok rate limits as retryable and non-ambiguous', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
            'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down'],
        ], 429),
    ]);

    try {
        $adapter->publish(tikTokPublishingRequest($connection));
        test()->fail('Rate limits must block the submission.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('rate_limited')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeFalse();
    }
});

it('classifies creator posting restrictions without treating them as transient failures', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
            'error' => ['code' => 'spam_risk_user_banned_from_posting', 'message' => 'Creator restricted'],
        ], 200),
    ]);

    try {
        $adapter->publish(tikTokPublishingRequest($connection));
        test()->fail('Restricted creators must not publish through the API.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('creator_restricted')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->ambiguous)->toBeFalse();
    }
});

it('rejects revoked or disabled creator connections before contacting TikTok', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
    Http::fake();

    expect(fn () => $adapter->publish(tikTokPublishingRequest($connection)))
        ->toThrow(PublishingAdapterException::class, 'connection is unavailable');

    Http::assertNothingSent();
});

it('marks an ambiguous initialization response unknown instead of replaying the publish request', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(['error' => 'gateway'], 500),
    ]);

    try {
        $adapter->publish(tikTokPublishingRequest($connection));
        test()->fail('An initialization server error must be ambiguous.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('provider_error')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeTrue();
    }

    Http::assertSentCount(2);
});

it('retries the same chunk only after TikTok confirms that the first attempt transferred no bytes', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/tiktok.mp4')->andReturn(true);
    $disk->shouldReceive('readStream')->once()->with('assets/tiktok.mp4')->andReturn(tikTokVideoStream());
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);
    $uploadResponses = Http::sequence()->push(['error' => 'gateway'], 500)->push([], 201);

    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(tikTokOkResponse([
            'publish_id' => 'v_pub_file~v2.123456789',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=123&upload_token=temporary-secret',
        ]), 200),
        'https://open-upload.tiktokapis.com/*' => $uploadResponses,
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tikTokOkResponse([
            'status' => 'PROCESSING_UPLOAD', 'uploaded_bytes' => 0,
        ]), 200),
    ]);

    $result = $adapter->publish(tikTokPublishingRequest($connection));

    expect($result->status)->toBe('pending');
    Http::assertSentCount(5);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT'))->toHaveCount(2);
    expect(collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/video/init/')))->toHaveCount(1);
});

it('rejects an untrusted upload URL and treats the initialized post as ambiguous', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(tikTokCreatorInfoResponse(), 200),
        'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(tikTokOkResponse([
            'publish_id' => 'v_pub_file~v2.123456789',
            'upload_url' => 'https://example.invalid/video?token=secret',
        ]), 200),
    ]);

    try {
        $adapter->publish(tikTokPublishingRequest($connection));
        test()->fail('An untrusted upload host must be rejected.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('invalid_adapter_response')
            ->and($exception->ambiguous)->toBeTrue();
    }

    Http::assertSentCount(2);
});

it('reconciles TikTok processing, completion, and failure without changing the publish id', function (string $remoteStatus, string $expectedStatus) {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tikTokOkResponse([
            'status' => $remoteStatus,
            'publicaly_available_post_id' => ['745000001'],
            'fail_reason' => $remoteStatus === 'FAILED' ? 'duration_check_failed' : null,
            'uploaded_bytes' => 1024,
        ]), 200),
    ]);

    $result = $adapter->lookupStatus(new PublishingRemotePublication(
        network: 'tiktok',
        destinationExternalId: 'open-id-123',
        externalPublicationId: 'v_pub_file~v2.123456789',
        idempotencyKey: 'tiktok-publication-key',
        correlationId: 'tiktok-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
    ));

    expect($result)->not->toBeNull()
        ->and($result->externalId)->toBe('v_pub_file~v2.123456789')
        ->and($result->status)->toBe($expectedStatus)
        ->and($result->payload['remote_status'])->toBe($remoteStatus)
        ->and($result->payload['public_post_ids'])->toBe(['745000001']);
})->with([
    'processing upload' => ['PROCESSING_UPLOAD', 'pending'],
    'complete' => ['PUBLISH_COMPLETE', 'published'],
    'failed' => ['FAILED', 'failed'],
]);

it('rejects a creator connection that does not own the requested destination', function () {
    [, $connection, $adapter] = tikTokPublishingContext();
    Http::fake();

    $request = new PublishingAdapterRequest(
        network: 'tiktok',
        destinationExternalId: 'different-open-id',
        content: 'A video from CR8OR.',
        media: tikTokVideoMedia(),
        scheduledAt: null,
        idempotencyKey: 'tiktok-publication-key',
        correlationId: 'tiktok-correlation-id',
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
        settings: tikTokSettings(),
    );

    expect(fn () => $adapter->publish($request))
        ->toThrow(PublishingAdapterException::class, 'does not match the requested creator');

    Http::assertNothingSent();
});