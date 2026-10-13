<?php

use App\Contracts\CredentialResolver;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingRemotePublication;
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
use App\Services\Publishing\XPublishingAdapter;
use App\Services\PublishingAdapterRegistry;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const X_USER_ID = '123456789012345678';
const X_POST_ID = '1900000000000000000';
const X_TEXT = 'A CR8OR update for X.';
const X_SCOPES = ['users.read', 'tweet.read', 'tweet.write', 'offline.access'];

/** @param list<string> $scopes
 * @return array{Organization, Enterprise, IntegrationConnection, Publication, PublishingJob, XPublishingAdapter, PublishingAdapterRequest}
 */
function xPublishingContext(array $scopes = X_SCOPES): array
{
    static $counter = 0;
    $counter++;
    config(['publishing.adapters.x.enabled' => false, 'publishing.x.api_base_url' => 'https://api.x.com/2']);

    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'name' => 'X', 'type' => 'x']);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->id, 'marketing_strategy_id' => $strategy->id]);
    $content = ContentItem::query()->create([
        'enterprise_id' => $enterprise->id,
        'campaign_id' => $campaign->id,
        'channel_id' => $channel->id,
        'title' => 'X post',
        'body' => X_TEXT,
        'status' => ContentItem::STATUS_DRAFT,
    ]);
    $identity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'x',
        'external_id' => X_USER_ID,
        'name' => 'CR8OR Test',
    ]);
    $reference = app(SocialCredentialStore::class)->store($organization, 'x', new SocialTokenSet(
        accessToken: 'x-user-access-token',
        refreshToken: 'x-refresh-token',
        accessTokenExpiresAt: now()->addHour()->toImmutable(),
        refreshTokenExpiresAt: null,
        scopes: $scopes,
    ));
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'social_identity_id' => $identity->id,
        'provider' => 'x',
        'external_account_id' => X_USER_ID,
        'credential_reference' => $reference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $account = SocialAccount::query()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => 'x',
        'name' => 'CR8OR Test',
        'external_id' => X_USER_ID,
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);
    $publication = Publication::factory()->create([
        'enterprise_id' => $enterprise->id,
        'content_item_id' => $content->id,
        'channel_id' => $channel->id,
        'social_account_id' => $account->id,
        'idempotency_key' => 'x-idempotency-'.$counter,
        'correlation_id' => 'x-correlation-'.$counter,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now(),
    ]);
    $job = PublishingJob::query()->create([
        'enterprise_id' => $enterprise->id,
        'publication_id' => $publication->id,
        'idempotency_key' => 'publish-'.$publication->idempotency_key,
        'status' => PublishingJob::STATUS_PENDING,
        'adapter_key' => 'x',
        'network' => 'x',
        'destination_external_id' => X_USER_ID,
    ]);
    $job->start()->save();

    $resolver = Mockery::mock(CredentialResolver::class);
    $resolver->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'x')
        ->andReturn('x-user-access-token');
    app()->instance(CredentialResolver::class, $resolver);

    $request = new PublishingAdapterRequest(
        network: 'x',
        destinationExternalId: X_USER_ID,
        content: X_TEXT,
        media: [],
        scheduledAt: now()->toIso8601String(),
        idempotencyKey: $publication->idempotency_key,
        correlationId: (string) $publication->correlation_id,
        integrationConnectionId: (int) $connection->id,
        credentialReference: (string) $connection->credential_reference,
        settings: ['__type' => 'x', 'title' => 'X post', 'metadata' => []],
        publishingJobId: (int) $job->id,
    );

    return [$organization, $enterprise, $connection, $publication, $job, app(XPublishingAdapter::class), $request];
}

/** @return array{data: array{id: string, name: string, username: string}} */
function xApiUser(): array
{
    return ['data' => ['id' => X_USER_ID, 'name' => 'CR8OR Test', 'username' => 'cr8or_test']];
}

/** @return array{data: array{id: string, text: string}} */
function xApiPost(): array
{
    return ['data' => ['id' => X_POST_ID, 'text' => X_TEXT]];
}

it('declares conservative text-only capabilities and stays disabled by default', function () {
    $adapter = app(XPublishingAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->network())->toBe('x')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.status.lookup'))->toBeTrue()
        ->and($capabilities->supportedAccountTypes)->toBe(['x.user'])
        ->and($capabilities->supportedDestinationTypes)->toBe(['x.user'])
        ->and($capabilities->supportedMediaTypes)->toBe([])
        ->and($capabilities->maxTextLength)->toBe(280)
        ->and($capabilities->maxMediaItems)->toBeNull()
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
        ->and(config('publishing.adapters.x.class'))->toBe(XPublishingAdapter::class)
        ->and(config('publishing.adapters.x.enabled'))->toBeFalse();
});

it('resolves through the shared adapter registry only when explicitly enabled', function () {
    config(['publishing.adapters.x.enabled' => true]);

    $resolution = app(PublishingAdapterRegistry::class)->resolve('x');

    expect($resolution->adapter)->toBeInstanceOf(XPublishingAdapter::class)
        ->and($resolution->adapterKey)->toBe('x')
        ->and($resolution->network)->toBe('x')
        ->and($resolution->useNativeScheduling)->toBeFalse();
});

it('publishes text through the X v2 user-context endpoint and persists only safe publication state', function () {
    [, , , , $job, $adapter, $request] = xPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if ($httpRequest->url() === 'https://api.x.com/2/users/me?user.fields=id%2Cusername'
            || str_starts_with($httpRequest->url(), 'https://api.x.com/2/users/me?')) {
            return Http::response(xApiUser());
        }
        if ($httpRequest->method() === 'POST' && $httpRequest->url() === 'https://api.x.com/2/tweets') {
            expect($httpRequest->data())->toBe(['text' => X_TEXT])
                ->and($httpRequest->hasHeader('Authorization'))->toBeTrue();

            return Http::response(xApiPost(), 201);
        }

        return Http::response(['title' => 'unexpected request'], 404);
    });

    $result = $adapter->publish($request);
    $job->refresh();

    expect($result->status)->toBe('published')
        ->and($result->externalId)->toBe(X_POST_ID)
        ->and($result->externalUrl)->toBe('https://x.com/i/web/status/'.X_POST_ID)
        ->and($result->correlationId)->toBe($request->correlationId)
        ->and($result->idempotencyKey)->toBe($request->idempotencyKey)
        ->and($result->payload['network'])->toBe('x')
        ->and($job->adapterState()['phase'])->toBe('published')
        ->and($job->adapterState()['post_id'])->toBe(X_POST_ID)
        ->and($job->getRawOriginal('adapter_state_encrypted'))->not->toContain('x-user-access-token');
});

it('rejects media and weighted text overflow before making API requests', function () {
    [, , , , , $adapter, $request] = xPublishingContext();
    Http::fake();

    $withMedia = new PublishingAdapterRequest(
        network: 'x',
        destinationExternalId: $request->destinationExternalId,
        content: X_TEXT,
        media: [['mime_type' => 'image/jpeg', 'disk' => 'r2', 'path' => 'posts/image.jpg', 'size' => 100]],
        scheduledAt: $request->scheduledAt,
        idempotencyKey: $request->idempotencyKey,
        correlationId: $request->correlationId,
        integrationConnectionId: $request->integrationConnectionId,
        credentialReference: $request->credentialReference,
        settings: $request->settings,
        publishingJobId: $request->publishingJobId,
    );
    expect(fn () => $adapter->publish($withMedia))
        ->toThrow(PublishingAdapterException::class, 'Text-only posts are supported.');

    $tooLong = new PublishingAdapterRequest(
        network: 'x',
        destinationExternalId: $request->destinationExternalId,
        content: str_repeat('a', 281),
        media: [],
        scheduledAt: $request->scheduledAt,
        idempotencyKey: $request->idempotencyKey,
        correlationId: $request->correlationId,
        integrationConnectionId: $request->integrationConnectionId,
        credentialReference: $request->credentialReference,
        settings: $request->settings,
        publishingJobId: $request->publishingJobId,
    );
    expect(fn () => $adapter->publish($tooLong))
        ->toThrow(PublishingAdapterException::class, '280 weighted-character limit');

    Http::assertNothingSent();
});

it('uses X weighted length for URLs and emoji rather than raw byte length', function () {
    [, , , , , $adapter, $request] = xPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if (str_contains($httpRequest->url(), '/users/me')) {
            return Http::response(xApiUser());
        }

        return Http::response(xApiPost(), 201);
    });
    $text = str_repeat('a', 256).' https://example.com';
    $valid = new PublishingAdapterRequest(
        network: 'x', destinationExternalId: $request->destinationExternalId, content: $text, media: [],
        scheduledAt: $request->scheduledAt, idempotencyKey: $request->idempotencyKey,
        correlationId: $request->correlationId, integrationConnectionId: $request->integrationConnectionId,
        credentialReference: $request->credentialReference, settings: $request->settings, publishingJobId: $request->publishingJobId,
    );

    expect(fn () => $adapter->publish($valid))->not->toThrow(PublishingAdapterException::class);

    [, , , , , $emojiAdapter, $emojiRequest] = xPublishingContext();
    $emojiText = str_repeat('a', 278).'😀';
    $emojiRequest = new PublishingAdapterRequest(
        network: 'x', destinationExternalId: $emojiRequest->destinationExternalId, content: $emojiText, media: [],
        scheduledAt: $emojiRequest->scheduledAt, idempotencyKey: $emojiRequest->idempotencyKey,
        correlationId: $emojiRequest->correlationId, integrationConnectionId: $emojiRequest->integrationConnectionId,
        credentialReference: $emojiRequest->credentialReference, settings: $emojiRequest->settings, publishingJobId: $emojiRequest->publishingJobId,
    );
    expect(fn () => $emojiAdapter->publish($emojiRequest))->not->toThrow(PublishingAdapterException::class);

    [, , , , , $cjkAdapter, $cjkRequest] = xPublishingContext();
    $cjkText = str_repeat('界', 140);
    $cjkRequest = new PublishingAdapterRequest(
        network: 'x', destinationExternalId: $cjkRequest->destinationExternalId, content: $cjkText, media: [],
        scheduledAt: $cjkRequest->scheduledAt, idempotencyKey: $cjkRequest->idempotencyKey,
        correlationId: $cjkRequest->correlationId, integrationConnectionId: $cjkRequest->integrationConnectionId,
        credentialReference: $cjkRequest->credentialReference, settings: $cjkRequest->settings, publishingJobId: $cjkRequest->publishingJobId,
    );
    expect(fn () => $cjkAdapter->publish($cjkRequest))->not->toThrow(PublishingAdapterException::class);
});

it('requires the write scope before sending a request', function () {
    [, , , , , $adapter, $request] = xPublishingContext(['users.read', 'tweet.read']);
    Http::fake();

    try {
        $adapter->publish($request);
        throw new LogicException('Expected a permission error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('permission_denied');
    }

    Http::assertNothingSent();
});

it('blocks duplicate submission after an ambiguous network timeout', function () {
    [, , , , $job, $adapter, $request] = xPublishingContext();
    $postAttempts = 0;
    Http::fake(function (Request $httpRequest) use (&$postAttempts) {
        if (str_contains($httpRequest->url(), '/users/me')) {
            return Http::response(xApiUser());
        }
        if ($httpRequest->method() === 'POST' && str_ends_with($httpRequest->url(), '/tweets')) {
            $postAttempts++;
            throw new ConnectionException('simulated timeout');
        }

        return Http::response([], 404);
    });

    try {
        $adapter->publish($request);
        throw new LogicException('Expected an ambiguous timeout.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('timeout')->and($exception->ambiguous)->toBeTrue();
    }
    $job->refresh();
    expect($job->adapterState()['phase'])->toBe('submitting');

    try {
        $adapter->publish($request);
        throw new LogicException('Expected duplicate submission to be blocked.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('ambiguous_publish_outcome')->and($exception->ambiguous)->toBeTrue();
    }
    expect($postAttempts)->toBe(1);
});

it('classifies API usage limits without treating a definitive rejection as ambiguous', function () {
    [, , , , $job, $adapter, $request] = xPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if (str_contains($httpRequest->url(), '/users/me')) {
            return Http::response(xApiUser());
        }

        return Http::response(['title' => 'Too Many Requests'], 429);
    });

    try {
        $adapter->publish($request);
        throw new LogicException('Expected a rate-limit error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('rate_limited')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->ambiguous)->toBeFalse();
    }
    $job->refresh();
    expect($job->adapterState()['phase'])->toBe('ready');
});

it('classifies an access-tier restriction without retrying an operation the app cannot access', function () {
    [, , , , , $adapter, $request] = xPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if (str_contains($httpRequest->url(), '/users/me')) {
            return Http::response(xApiUser());
        }

        return Http::response(['detail' => 'Your client app is not enrolled in the required access tier.'], 403);
    });

    try {
        $adapter->publish($request);
        throw new LogicException('Expected an access-tier restriction.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('access_tier_restricted')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->ambiguous)->toBeFalse();
    }
});

it('distinguishes a monthly product cap from a transient rate limit', function () {
    [, , , , , $adapter, $request] = xPublishingContext();
    Http::fake(function (Request $httpRequest) {
        if (str_contains($httpRequest->url(), '/users/me')) {
            return Http::response(xApiUser());
        }

        return Http::response(['detail' => 'Usage cap exceeded: Monthly product cap'], 429);
    });

    try {
        $adapter->publish($request);
        throw new LogicException('Expected a usage-cap error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('quota_exhausted')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->ambiguous)->toBeFalse();
    }
});

it('requires renewed credentials when the shared credential resolver rejects the grant', function () {
    [, , $connection, , , $adapter, $request] = xPublishingContext();
    $resolver = Mockery::mock(CredentialResolver::class);
    $resolver->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'x')
        ->andThrow(new SocialAuthorizationException('invalid_grant', 'The X refresh grant is invalid.'));
    app()->instance(CredentialResolver::class, $resolver);
    $adapter = app(XPublishingAdapter::class);
    Http::fake();

    try {
        $adapter->publish($request);
        throw new LogicException('Expected a credential renewal error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('authentication_required');
    }
    Http::assertNothingSent();
});

it('rejects a connection whose enterprise context differs from the publishing job', function () {
    [, $enterprise, $connection, , $job, $adapter, $request] = xPublishingContext();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $enterprise->organization_id]);
    $job->forceFill(['enterprise_id' => $otherEnterprise->id])->save();
    Http::fake();

    expect(fn () => $adapter->publish($request))
        ->toThrow(PublishingAdapterException::class, 'does not match the connection, destination or publication request');
    Http::assertNothingSent();
});

it('reconciles an existing post only when its author matches the authorized destination', function () {
    [, , $connection, $publication, $job, $adapter] = xPublishingContext();
    $job->succeed()->save();
    Http::fake(function (Request $httpRequest) {
        if (str_contains($httpRequest->url(), '/tweets/'.X_POST_ID)) {
            return Http::response(['data' => [
                'id' => X_POST_ID,
                'author_id' => X_USER_ID,
                'text' => X_TEXT,
                'created_at' => '2026-10-13T10:00:00.000Z',
            ]]);
        }

        return Http::response([], 404);
    });
    $remote = new PublishingRemotePublication(
        network: 'x',
        destinationExternalId: X_USER_ID,
        externalPublicationId: X_POST_ID,
        idempotencyKey: $publication->idempotency_key,
        correlationId: (string) $publication->correlation_id,
        integrationConnectionId: (int) $connection->id,
        credentialReference: (string) $connection->credential_reference,
        publishingJobId: (int) $job->id,
    );

    $result = $adapter->lookupStatus($remote);

    expect($result?->status)->toBe('published')
        ->and($result?->externalUrl)->toBe('https://x.com/i/web/status/'.X_POST_ID);
});

it('rejects a remote post whose author differs from the selected destination', function () {
    [, , $connection, $publication, $job] = xPublishingContext();
    $job->succeed()->save();
    Http::fake(fn () => Http::response(['data' => ['id' => X_POST_ID, 'author_id' => '999999999', 'text' => X_TEXT]]));
    $remote = new PublishingRemotePublication(
        network: 'x', destinationExternalId: X_USER_ID, externalPublicationId: X_POST_ID,
        idempotencyKey: $publication->idempotency_key, correlationId: (string) $publication->correlation_id,
        integrationConnectionId: (int) $connection->id, credentialReference: (string) $connection->credential_reference,
        publishingJobId: (int) $job->id,
    );

    expect(fn () => app(XPublishingAdapter::class)->lookupStatus($remote))
        ->toThrow(PublishingAdapterException::class, 'does not match the requested ID and authorized destination');
});