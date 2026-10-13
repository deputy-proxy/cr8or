<?php

use App\Contracts\CredentialResolver;
use App\Data\PublishingAdapterRequest;
use App\Data\Social\SocialTokenSet;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use App\Exceptions\SocialAuthorizationException;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\MarketingStrategy;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\PublishingJob;
use App\Models\SocialAccount;
use App\Models\SocialIdentity;
use App\Services\Publishing\ThreadsPublishingAdapter;
use App\Services\PublishingService;
use App\Services\Social\SocialCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const THREADS_USER_ID = '17841400000000000';
const THREADS_CONTAINER_ID = '17900000000000001';
const THREADS_POST_ID = '17900000000000002';
const THREADS_CORRELATION = 'threads-test-correlation';
const THREADS_KEY = 'threads-test-idempotency';

/** @return array{Organization, Enterprise, IntegrationConnection, SocialAccount, ContentItem, Publication, PublishingJob, ThreadsPublishingAdapter, PublishingAdapterRequest} */
function threadsPublishingContext(
    string $content = 'A post from CR8OR.',
    array $media = [],
    array $metadata = [],
    array $scopes = ['threads_basic', 'threads_content_publish'],
    bool $tokenRevoked = false,
): array {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
        'publishing.adapters.threads.enabled' => true,
        'publishing.threads.api_version' => 'v1.0',
        'publishing.threads.media_url_expiry_minutes' => 60,
        'social_authorization.timeout' => 5,
    ]);

    static $contextNumber = 0;
    $contextNumber++;
    $idempotencyKey = THREADS_KEY.'-'.$contextNumber;
    $correlationId = THREADS_CORRELATION.'-'.$contextNumber;
    $enterprise = Enterprise::factory()->create();
    $organization = Organization::query()->findOrFail($enterprise->organization_id);
    $identity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'threads',
        'external_id' => THREADS_USER_ID,
        'name' => 'cr8or_threads_test',
    ]);
    $credentialReference = app(SocialCredentialStore::class)->store(
        $organization,
        'threads',
        new SocialTokenSet(
            accessToken: 'threads-user-access-token',
            refreshToken: 'threads-user-refresh-token',
            accessTokenExpiresAt: CarbonImmutable::now()->addHour(),
            refreshTokenExpiresAt: null,
            scopes: $scopes,
        ),
    );
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'social_identity_id' => $identity->id,
        'provider' => 'threads',
        'external_account_id' => THREADS_USER_ID,
        'credential_reference' => $credentialReference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'name' => 'Threads', 'type' => 'social']);
    $account = SocialAccount::query()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => 'threads',
        'name' => 'cr8or_threads_test',
        'external_id' => THREADS_USER_ID,
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);
    $strategy = MarketingStrategy::factory()->for($enterprise)->create();
    $campaign = Campaign::factory()->for($strategy, 'marketingStrategy')->create();
    $contentItem = ContentItem::factory()->forCampaign($campaign)->create([
        'title' => 'Threads test post',
        'body' => $content,
        'metadata' => $metadata,
        'status' => ContentItem::STATUS_PUBLICATION_READY,
    ]);
    $publication = Publication::factory()->create([
        'enterprise_id' => $enterprise->id,
        'content_item_id' => $contentItem->id,
        'channel_id' => $channel->id,
        'social_account_id' => $account->id,
        'status' => Publication::STATUS_SCHEDULED,
        'idempotency_key' => $idempotencyKey,
        'correlation_id' => $correlationId,
        'scheduled_at' => now(),
    ]);
    $job = PublishingJob::query()->create([
        'enterprise_id' => $enterprise->id,
        'publication_id' => $publication->id,
        'idempotency_key' => 'publish-'.$idempotencyKey,
        'status' => PublishingJob::STATUS_PENDING,
        'adapter_key' => 'threads',
        'network' => 'threads',
        'destination_external_id' => THREADS_USER_ID,
    ]);
    $job->start()->save();

    $credentials = Mockery::mock(CredentialResolver::class);
    $tokenResolution = $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'threads');
    if ($tokenRevoked) {
        $tokenResolution->andThrow(new SocialAuthorizationException('invalid_grant', 'The Threads refresh grant has been revoked.'));
    } else {
        $tokenResolution->andReturn('threads-user-access-token');
    }
    app()->instance(CredentialResolver::class, $credentials);

    $request = new PublishingAdapterRequest(
        network: 'threads',
        destinationExternalId: THREADS_USER_ID,
        content: $content,
        media: $media,
        scheduledAt: CarbonImmutable::now()->toIso8601String(),
        idempotencyKey: $idempotencyKey,
        correlationId: $correlationId,
        integrationConnectionId: (int) $connection->id,
        credentialReference: $connection->credential_reference,
        settings: ['title' => $contentItem->title, 'metadata' => $contentItem->metadata],
        publishingJobId: (int) $job->id,
    );

    return [$organization, $enterprise, $connection, $account, $contentItem, $publication, $job, app(ThreadsPublishingAdapter::class), $request];
}

function threadsContainerStatus(string $id, string $status): array
{
    return ['id' => $id, 'status' => $status];
}

it('declares a narrow Threads capability set and stays disabled by default', function () {
    config(['publishing.adapters.threads.enabled' => false]);
    $adapter = app(ThreadsPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('threads')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.edit'))->toBeFalse()
        ->and($capabilities->supportedAccountTypes)->toBe(['threads.user'])
        ->and($capabilities->supportedDestinationTypes)->toBe(['threads.user'])
        ->and($capabilities->supportedMediaTypes)->toBe(['image/jpeg', 'image/png', 'video/mp4', 'video/quicktime'])
        ->and($capabilities->maxTextLength)->toBe(500)
        ->and($capabilities->maxMediaItems)->toBe(1)
        ->and($capabilities->maxVideoDurationSeconds)->toBe(300)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.threads.class'))->toBe(ThreadsPublishingAdapter::class)
        ->and(config('publishing.adapters.threads.enabled'))->toBeFalse()
        ->and(config('social_authorization.providers.threads.scopes'))->toBe(['threads_basic', 'threads_content_publish'])
        ->and(config('social_authorization.providers.threads.identity.id_path'))->toBe('id')
        ->and(config('social_authorization.providers.threads.identity.name_path'))->toBe('username')
        ->and(config('social_authorization.providers.threads.refresh.strategy'))->toBe('threads_long_lived');
});

it('publishes a text-only post through the Threads container lifecycle without leaking credentials', function () {
    [, , , , , , $job, $adapter, $request] = threadsPublishingContext(metadata: ['threads' => ['topic_tag' => 'architecture', 'reply_control' => 'accounts_you_follow']]);
    Http::fake(function (Request $httpRequest) {
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')) {
            return Http::response(['id' => THREADS_CONTAINER_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_CONTAINER_ID)) {
            return Http::response(threadsContainerStatus(THREADS_CONTAINER_ID, 'FINISHED'));
        }
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads_publish')) {
            return Http::response(['id' => THREADS_POST_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_POST_ID)) {
            return Http::response(['id' => THREADS_POST_ID, 'permalink' => 'https://www.threads.net/@cr8or_threads_test/post/abc123', 'username' => 'cr8or_threads_test']);
        }

        return Http::response(['error' => ['message' => 'unexpected request']], 404);
    });

    $result = $adapter->publish($request);

    expect($result->externalId)->toBe(THREADS_POST_ID)
        ->and($result->status)->toBe('published')
        ->and($result->externalUrl)->toBe('https://www.threads.net/@cr8or_threads_test/post/abc123')
        ->and($result->correlationId)->toBe($request->correlationId)
        ->and($result->idempotencyKey)->toBe($request->idempotencyKey)
        ->and($result->payload['container_id'])->toBe(THREADS_CONTAINER_ID)
        ->and($job->refresh()->adapterState()['phase'])->toBe('published')
        ->and(json_encode($result->payload))->not->toContain('threads-user-access-token');

    Http::assertSent(fn (Request $httpRequest): bool => $httpRequest->method() === 'POST'
        && str_ends_with($httpRequest->url(), '/threads')
        && $httpRequest->hasHeader('Authorization', 'Bearer threads-user-access-token')
        && $httpRequest['media_type'] === 'TEXT'
        && $httpRequest['text'] === 'A post from CR8OR.'
        && $httpRequest['topic_tag'] === 'architecture'
        && $httpRequest['reply_control'] === 'accounts_you_follow');
    Http::assertSent(fn (Request $httpRequest): bool => $httpRequest->method() === 'POST'
        && str_ends_with($httpRequest->url(), '/threads_publish')
        && $httpRequest['creation_id'] === THREADS_CONTAINER_ID);
});

it('publishes a single R2 image using a temporary HTTPS URL and alt text', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext(media: [[
        'asset_id' => 42, 'mime_type' => 'image/jpeg', 'size' => 2048, 'disk' => 'r2', 'path' => 'assets/threads.jpg',
        'metadata' => ['alt_text' => 'A bicycle beside the sea.'],
    ]]);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with('assets/threads.jpg')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')->once()->with('assets/threads.jpg', Mockery::type(DateTimeInterface::class))
        ->andReturn('https://r2.example.test/assets/threads.jpg?signature=temporary-secret');
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);
    Http::fake(function (Request $httpRequest) {
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')) {
            return Http::response(['id' => THREADS_CONTAINER_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_CONTAINER_ID)) {
            return Http::response(threadsContainerStatus(THREADS_CONTAINER_ID, 'FINISHED'));
        }
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads_publish')) {
            return Http::response(['id' => THREADS_POST_ID]);
        }

        return Http::response([], 404);
    });

    $result = $adapter->publish($request);

    expect($result->status)->toBe('published')
        ->and(json_encode($result->payload))->not->toContain('temporary-secret');
    Http::assertSent(fn (Request $httpRequest): bool => str_ends_with($httpRequest->url(), '/threads')
        && $httpRequest['media_type'] === 'IMAGE'
        && $httpRequest['image_url'] === 'https://r2.example.test/assets/threads.jpg?signature=temporary-secret'
        && $httpRequest['alt_text'] === 'A bicycle beside the sea.');
});

it('rejects invalid text, unsupported carousel media and overlong videos before network submission', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext(content: str_repeat('x', 501));
    Http::preventStrayRequests();
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, '500 characters');

    [, , , , , , , $adapter, $request] = threadsPublishingContext(media: [
        ['mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'r2', 'path' => 'assets/a.jpg'],
        ['mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'r2', 'path' => 'assets/b.jpg'],
    ]);
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'carousel posts are not enabled');

    [, , , , , , , $adapter, $request] = threadsPublishingContext(media: [[
        'mime_type' => 'video/mp4', 'size' => 1024, 'disk' => 'r2', 'path' => 'assets/long.mp4', 'duration_seconds' => 301,
    ]]);
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'five minutes');
});

it('rejects invalid topic tags and reply-control values before making a provider request', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext(metadata: ['threads' => ['topic_tag' => '#architecture']]);
    Http::preventStrayRequests();
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'must not include a leading #');

    [, , , , , , , $adapter, $request] = threadsPublishingContext(metadata: ['threads' => ['reply_control' => 'anyone']]);
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'reply control must be');
});

it('classifies Threads rate limits as retryable without marking the result ambiguous', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext();
    Http::fake(fn (Request $httpRequest) => $httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')
        ? Http::response(['error' => ['code' => 4, 'message' => 'rate limit']], 429)
        : Http::response([], 404));

    try {
        $adapter->publish($request);
        test()->fail('Expected a retryable rate-limit failure.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('rate_limited')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeFalse();
    }
});

it('fails a container that Threads definitively reports as errored', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')) {
            return Http::response(['id' => THREADS_CONTAINER_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_CONTAINER_ID)) {
            return Http::response(threadsContainerStatus(THREADS_CONTAINER_ID, 'ERROR'));
        }

        return Http::response([], 404);
    });

    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'processing failed or expired');
});

it('maps a revoked Threads refresh grant to an authentication failure', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext(tokenRevoked: true);
    Http::preventStrayRequests();
    expect(fn () => $adapter->publish($request))
        ->toThrow(PublishingAdapterException::class, 'Threads authorization is unavailable and must be renewed');
});

it('rejects a publishing job that belongs to another enterprise before provider access', function () {
    [, , , , , , $job, $adapter, $request] = threadsPublishingContext();
    $otherEnterprise = Enterprise::factory()->create();
    $otherOrganization = Organization::query()->findOrFail($otherEnterprise->organization_id);
    $otherIdentity = SocialIdentity::query()->create([
        'organization_id' => $otherOrganization->id,
        'provider' => 'threads',
        'external_id' => THREADS_USER_ID,
        'name' => 'other_threads_identity',
    ]);
    $otherReference = app(SocialCredentialStore::class)->store(
        $otherOrganization,
        'threads',
        new SocialTokenSet(
            accessToken: 'other-threads-access-token',
            refreshToken: null,
            accessTokenExpiresAt: CarbonImmutable::now()->addHour(),
            refreshTokenExpiresAt: null,
            scopes: ['threads_basic', 'threads_content_publish'],
        ),
    );
    $otherConnection = IntegrationConnection::query()->create([
        'organization_id' => $otherOrganization->id,
        'enterprise_id' => $otherEnterprise->id,
        'social_identity_id' => $otherIdentity->id,
        'provider' => 'threads',
        'external_account_id' => THREADS_USER_ID,
        'credential_reference' => $otherReference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $foreignRequest = new PublishingAdapterRequest(
        network: 'threads', destinationExternalId: THREADS_USER_ID, content: $request->content,
        media: $request->media, scheduledAt: $request->scheduledAt, idempotencyKey: $request->idempotencyKey,
        correlationId: $request->correlationId, integrationConnectionId: (int) $otherConnection->id,
        credentialReference: $otherReference, settings: $request->settings, publishingJobId: (int) $job->id,
    );
    Http::preventStrayRequests();

    expect(fn () => $adapter->publish($foreignRequest))
        ->toThrow(PublishingAdapterException::class, 'does not match the connection, destination or publication request');
});

it('fails closed when the Threads OAuth grant is missing the publishing scope', function () {
    [, , , , , , , $adapter, $request] = threadsPublishingContext(scopes: ['threads_basic']);
    Http::preventStrayRequests();
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'missing a required publishing or basic-read scope');
});

it('returns pending for an in-progress container and later reconciles it to the actual post ID', function () {
    [, , $connection, , , $publication, $job, $adapter, $request] = threadsPublishingContext();
    $containerStatus = 'IN_PROGRESS';
    Http::fake(function (Request $httpRequest) use (&$containerStatus) {
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')) {
            return Http::response(['id' => THREADS_CONTAINER_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_CONTAINER_ID)) {
            return Http::response(threadsContainerStatus(THREADS_CONTAINER_ID, $containerStatus));
        }
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads_publish')) {
            return Http::response(['id' => THREADS_POST_ID]);
        }

        return Http::response([], 404);
    });
    $result = $adapter->publish($request);
    expect($result->status)->toBe('pending')->and($result->externalId)->toBe(THREADS_CONTAINER_ID);

    $job->forceFill(['external_id' => THREADS_CONTAINER_ID, 'external_url' => null])->succeed()->save();
    $publication->markSubmitted(THREADS_CONTAINER_ID)->save();
    $containerStatus = 'FINISHED';

    expect(app(PublishingService::class)->reconcileStatus($publication->refresh()))->toBe('published')
        ->and($publication->refresh()->status)->toBe(Publication::STATUS_SUCCEEDED)
        ->and($job->refresh()->external_id)->toBe(THREADS_POST_ID);
});

it('persists the provider failure code when container reconciliation fails', function () {
    [, , , , , $publication, $job, $adapter, $request] = threadsPublishingContext();
    $containerStatus = 'IN_PROGRESS';
    Http::fake(function (Request $httpRequest) use (&$containerStatus) {
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')) {
            return Http::response(['id' => THREADS_CONTAINER_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_CONTAINER_ID)) {
            return Http::response(threadsContainerStatus(THREADS_CONTAINER_ID, $containerStatus));
        }

        return Http::response([], 404);
    });
    expect($adapter->publish($request)->status)->toBe('pending');
    $job->forceFill(['external_id' => THREADS_CONTAINER_ID])->succeed()->save();
    $publication->markSubmitted(THREADS_CONTAINER_ID)->save();
    $containerStatus = 'ERROR';

    expect(app(PublishingService::class)->reconcileStatus($publication->refresh()))->toBe('failed')
        ->and($publication->refresh()->status)->toBe(Publication::STATUS_FAILED)
        ->and($publication->refresh()->failure_code)->toBe('container_failed');
});

it('persists the publish phase and marks a timed-out publish request ambiguous instead of retrying', function () {
    [, , , , , , $job, $adapter, $request] = threadsPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads')) {
            return Http::response(['id' => THREADS_CONTAINER_ID]);
        }
        if ($httpRequest->method() === 'GET' && str_ends_with((string) parse_url($httpRequest->url(), PHP_URL_PATH), '/'.THREADS_CONTAINER_ID)) {
            return Http::response(threadsContainerStatus(THREADS_CONTAINER_ID, 'FINISHED'));
        }
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/threads_publish')) {
            return Http::failedConnection();
        }

        return Http::response([], 404);
    });

    try {
        $adapter->publish($request);
        test()->fail('Expected the Threads publish response to be ambiguous.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->ambiguous)->toBeTrue()->and($exception->retryable)->toBeTrue();
    }

    $job->refresh();
    expect($job->adapterState()['phase'])->toBe('publishing')
        ->and($job->getRawOriginal('adapter_state_encrypted'))->not->toContain(THREADS_CONTAINER_ID);
});

it('moves the publication to unknown after an ambiguous reconciliation and never resubmits the container', function () {
    [, , , , , $publication, $job] = threadsPublishingContext();
    $state = [
        'phase' => 'publishing',
        'container_id' => THREADS_CONTAINER_ID,
        'media_type' => 'TEXT',
        'destination_external_id' => THREADS_USER_ID,
        'request_fingerprint' => 'a'.str_repeat('b', 63),
    ];
    $job->setAdapterState($state)->forceFill(['external_id' => THREADS_CONTAINER_ID])->succeed()->save();
    $publication->markSubmitted(THREADS_CONTAINER_ID)->save();
    config(['publishing.adapters.threads.enabled' => true]);
    Http::preventStrayRequests();

    expect(app(PublishingService::class)->reconcileStatus($publication->refresh()))->toBe('unknown')
        ->and($publication->refresh()->status)->toBe(Publication::STATUS_UNKNOWN)
        ->and($job->refresh()->status)->toBe(PublishingJob::STATUS_SUCCEEDED);
});