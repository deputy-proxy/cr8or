<?php

use App\Contracts\CredentialResolver;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingRemotePublication;
use App\Data\Social\SocialTokenSet;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
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
use App\Services\Publishing\YouTubePublishingAdapter;
use App\Services\PublishingAdapterRegistry;
use App\Services\PublishingService;
use App\Services\Social\SocialCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const YT_CHANNEL = 'UCabcdefghijklmnopqrstuv';
const YT_VIDEO = 'abcdefghijk';
const YT_KEY = 'youtube-publication-idempotency';
const YT_CORRELATION = 'youtube-correlation-id';

function youtubeVideoBytes(int $size = 300_000): string
{
    $header = "\x00\x00\x00\x18ftypisom";

    return $header.str_repeat('x', max(0, $size - strlen($header)));
}

function youtubeVideoResource(string $channel = YT_CHANNEL, string $video = YT_VIDEO, string $upload = 'uploaded', string $processing = 'processing', string $privacy = 'private'): array
{
    return ['id' => $video, 'snippet' => ['channelId' => $channel, 'title' => 'Uploaded title'], 'status' => ['uploadStatus' => $upload, 'privacyStatus' => $privacy], 'processingDetails' => ['processingStatus' => $processing]];
}

function youtubeChannelList(string $channel = YT_CHANNEL, string $longUploads = 'allowed'): array
{
    return ['items' => [['id' => $channel, 'status' => ['longUploadsStatus' => $longUploads]]]];
}

/** @return array{Organization, Enterprise, Channel, ContentItem, SocialAccount, IntegrationConnection, Publication, PublishingJob, YouTubePublishingAdapter, PublishingAdapterRequest} */
function youtubePublishingContext(array $youtubeMetadata = [], ?string $channelId = null): array
{
    static $contextNumber = 0;
    $contextNumber++;
    $channelId ??= YT_CHANNEL;
    $idempotencyKey = YT_KEY.'-'.$contextNumber;
    $correlationId = YT_CORRELATION.'-'.$contextNumber;
    config([
        'publishing.youtube.chunk_size_bytes' => 262144,
        'publishing.youtube.upload_timeout' => 10,
        'publishing.youtube.max_upload_bytes' => 256_000_000_000,
        'publishing.youtube.default_privacy_status' => 'private',
        'publishing.youtube.default_category_id' => null,
        'publishing.youtube.notify_subscribers' => false,
        'publishing.adapters.youtube.enabled' => false,
    ]);
    Storage::fake('r2');
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'name' => 'YouTube', 'type' => 'youtube']);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->id, 'marketing_strategy_id' => $strategy->id]);
    $content = ContentItem::query()->create([
        'enterprise_id' => $enterprise->id,
        'campaign_id' => $campaign->id,
        'channel_id' => $channel->id,
        'title' => 'Default content title',
        'body' => 'Default content description',
        'metadata' => $youtubeMetadata === [] ? null : ['youtube' => $youtubeMetadata],
        'status' => ContentItem::STATUS_DRAFT,
    ]);
    $identity = SocialIdentity::query()->create(['organization_id' => $organization->id, 'provider' => 'youtube', 'external_id' => $channelId, 'name' => 'Authorized YouTube channel']);
    $reference = app(SocialCredentialStore::class)->store($organization, 'youtube', new SocialTokenSet(
        accessToken: 'youtube-user-access-token', refreshToken: 'youtube-refresh-token',
        accessTokenExpiresAt: CarbonImmutable::now()->addHour(), refreshTokenExpiresAt: null,
        scopes: ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
    ));
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'social_identity_id' => $identity->id, 'provider' => 'youtube',
        'external_account_id' => $channelId, 'credential_reference' => $reference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $account = SocialAccount::query()->create([
        'enterprise_id' => $enterprise->id, 'channel_id' => $channel->id,
        'social_identity_id' => $identity->id, 'provider' => 'youtube',
        'name' => 'Authorized YouTube channel', 'external_id' => $channelId,
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);
    $publication = Publication::factory()->create([
        'enterprise_id' => $enterprise->id, 'content_item_id' => $content->id,
        'channel_id' => $channel->id, 'social_account_id' => $account->id,
        'idempotency_key' => $idempotencyKey, 'correlation_id' => $correlationId,
        'status' => Publication::STATUS_SCHEDULED, 'scheduled_at' => now(),
    ]);
    $job = PublishingJob::query()->create([
        'enterprise_id' => $enterprise->id, 'publication_id' => $publication->id,
        'idempotency_key' => 'publish-'.$idempotencyKey, 'status' => PublishingJob::STATUS_PENDING,
        'adapter_key' => 'youtube', 'network' => 'youtube', 'destination_external_id' => $channelId,
    ]);
    $job->start()->save();
    $resolver = Mockery::mock(CredentialResolver::class);
    $resolver->shouldReceive('resolveAccessToken')->with(Mockery::on(fn ($c): bool => $c instanceof IntegrationConnection && $c->is($connection)), 'youtube')->andReturn('youtube-user-access-token');
    app()->instance(CredentialResolver::class, $resolver);
    $bytes = youtubeVideoBytes();
    Storage::disk('r2')->put('videos/test.mp4', $bytes);
    $request = new PublishingAdapterRequest(
        network: 'youtube', destinationExternalId: $channelId, content: $content->body,
        media: [['asset_id' => 991, 'mime_type' => 'video/mp4', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'disk' => 'r2', 'path' => 'videos/test.mp4', 'duration_seconds' => 60, 'metadata' => ['duration_seconds' => 60]]],
        scheduledAt: CarbonImmutable::now(), idempotencyKey: $idempotencyKey, correlationId: $correlationId,
        integrationConnectionId: (int) $connection->id, credentialReference: $connection->credential_reference,
        settings: ['__type' => 'youtube', 'title' => $content->title, 'metadata' => $content->metadata ?? []],
        publishingJobId: (int) $job->id,
    );

    return [$organization, $enterprise, $channel, $content, $account, $connection, $publication, $job, app(YouTubePublishingAdapter::class), $request];
}

it('declares video publishing and status lookup while remaining disabled by default', function () {
    $adapter = app(YouTubePublishingAdapter::class);
    $capabilities = $adapter->capabilities();
    expect($adapter->network())->toBe('youtube')
        ->and($adapter->integrationKey())->toBe('publishing')
        ->and($adapter->supports('publication.publish'))->toBeTrue()
        ->and($adapter->supports('publication.status.lookup'))->toBeTrue()
        ->and($capabilities->supportedAccountTypes)->toBe(['youtube.channel'])
        ->and($capabilities->supportedDestinationTypes)->toBe(['youtube.channel'])
        ->and($capabilities->maxMediaItems)->toBe(1)
        ->and($capabilities->maxMediaSizeBytes)->toBe(256_000_000_000)
        ->and($capabilities->maxVideoDurationSeconds)->toBe(43_200)
        ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
        ->and($capabilities->editing)->toBe(PublishingCapabilitySupport::Unsupported)
        ->and(config('publishing.adapters.youtube.class'))->toBe(YouTubePublishingAdapter::class)
        ->and(config('publishing.adapters.youtube.enabled'))->toBeFalse();
});

it('resolves through the shared registry only when explicitly enabled', function () {
    config(['publishing.adapters.youtube.enabled' => true]);
    $resolution = app(PublishingAdapterRegistry::class)->resolve('youtube');
    expect($resolution->adapter)->toBeInstanceOf(YouTubePublishingAdapter::class)
        ->and($resolution->adapterKey)->toBe('youtube')
        ->and($resolution->network)->toBe('youtube')
        ->and($resolution->useNativeScheduling)->toBeFalse();
});

it('uploads through a resumable session and keeps the completed session state encrypted', function () {
    $metadata = ['title' => 'YouTube-specific title', 'description' => 'YouTube-specific description', 'tags' => ['launch', 'cr8or'], 'category_id' => '22', 'privacy_status' => 'unlisted', 'made_for_kids' => false, 'license' => 'creativeCommon', 'notify_subscribers' => true];
    [, , , , , , , $job, $adapter, $request] = youtubePublishingContext($metadata);
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=secret-session-token';
    $ranges = [];
    $ackedOffset = 0;
    Http::fake(function (Request $httpRequest) use ($session, &$ranges, &$ackedOffset) {
        if (str_starts_with($httpRequest->url(), 'https://www.googleapis.com/youtube/v3/channels')) {
            return Http::response(youtubeChannelList());
        }
        if ($httpRequest->method() === 'POST' && str_contains($httpRequest->url(), '/upload/youtube/v3/videos')) {
            expect($httpRequest->data()['snippet']['title'])->toBe('YouTube-specific title')
                ->and($httpRequest->data()['snippet']['description'])->toBe('YouTube-specific description')
                ->and($httpRequest->data()['snippet']['tags'])->toBe(['launch', 'cr8or'])
                ->and($httpRequest->data()['snippet']['categoryId'])->toBe('22')
                ->and($httpRequest->data()['status']['privacyStatus'])->toBe('unlisted')
                ->and($httpRequest->data()['status']['selfDeclaredMadeForKids'])->toBeFalse()
                ->and($httpRequest->data()['status']['license'])->toBe('creativeCommon');
            expect($httpRequest->url())->toContain('notifySubscribers=true');

            return Http::response('', 200, ['Location' => $session]);
        }
        if ($httpRequest->method() === 'PUT' && $httpRequest->url() === $session) {
            $range = (string) ($httpRequest->header('Content-Range')[0] ?? '');
            if (str_starts_with($range, 'bytes */')) {
                return $ackedOffset > 0 ? Http::response('', 308, ['Range' => 'bytes=0-'.($ackedOffset - 1)]) : Http::response('', 308);
            }
            $ranges[] = $range;
            if (str_starts_with($range, 'bytes 0-262143/')) {
                $ackedOffset = 262144;

                return Http::response('', 308, ['Range' => 'bytes=0-262143']);
            }
            if (str_starts_with($range, 'bytes 262144-')) {
                return Http::response(youtubeVideoResource(YT_CHANNEL, YT_VIDEO, 'uploaded', 'processing', 'unlisted'), 200);
            }
        }

        return Http::response(['error' => ['message' => 'unexpected request']], 404);
    });

    $result = $adapter->publish($request);
    $job->refresh();
    $state = $job->adapterState();
    expect($result->status)->toBe('pending')
        ->and($result->externalId)->toBe(YT_VIDEO)
        ->and($result->externalUrl)->toBe('https://www.youtube.com/watch?v='.YT_VIDEO)
        ->and($result->payload['requested_privacy_status'])->toBe('unlisted')
        ->and($state['upload_complete'])->toBeTrue()
        ->and($state['video_id'])->toBe(YT_VIDEO)
        ->and($state['requested_privacy_status'])->toBe('unlisted')
        ->and($state)->not->toHaveKey('session_uri')
        ->and($job->getRawOriginal('adapter_state_encrypted'))->not->toContain('secret-session-token')
        ->and($ranges)->toBe(['bytes 0-262143/300000', 'bytes 262144-299999/300000']);
});

it('reuses a completed upload result after a retry instead of creating a duplicate video', function () {
    [, , , , , , , , $adapter, $request] = youtubePublishingContext();
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=retry-session';
    $posts = 0;
    $puts = 0;
    $acked = 0;
    Http::fake(function (Request $httpRequest) use ($session, &$posts, &$puts, &$acked) {
        if (str_starts_with($httpRequest->url(), 'https://www.googleapis.com/youtube/v3/channels')) {
            return Http::response(youtubeChannelList());
        }
        if ($httpRequest->method() === 'POST' && str_contains($httpRequest->url(), '/upload/youtube/v3/videos')) {
            $posts++;

            return Http::response('', 200, ['Location' => $session]);
        }
        if ($httpRequest->method() === 'PUT' && $httpRequest->url() === $session) {
            $puts++;
            $range = (string) ($httpRequest->header('Content-Range')[0] ?? '');
            if (str_starts_with($range, 'bytes */')) {
                return $acked > 0 ? Http::response('', 308, ['Range' => 'bytes=0-'.($acked - 1)]) : Http::response('', 308);
            }
            if (str_starts_with($range, 'bytes 0-262143/')) {
                $acked = 262144;

                return Http::response('', 308, ['Range' => 'bytes=0-262143']);
            }
            if (str_starts_with($range, 'bytes 262144-')) {
                return Http::response(youtubeVideoResource(), 200);
            }
        }

        return Http::response([], 404);
    });

    $first = $adapter->publish($request);
    $second = $adapter->publish($request);
    expect($first->externalId)->toBe(YT_VIDEO)->and($second->externalId)->toBe(YT_VIDEO)
        ->and($posts)->toBe(1)->and($puts)->toBe(3);
});

it('reports processing as pending and only reports publication after YouTube processing succeeds', function () {
    [, , , , , $connection, , , $adapter] = youtubePublishingContext();
    $remote = new PublishingRemotePublication(
        network: 'youtube', destinationExternalId: YT_CHANNEL, externalPublicationId: YT_VIDEO,
        idempotencyKey: YT_KEY, correlationId: YT_CORRELATION,
        integrationConnectionId: (int) $connection->id, credentialReference: $connection->credential_reference,
        metadata: ['requested_privacy_status' => 'private'],
    );
    $uploadStatus = 'uploaded';
    $processingStatus = 'processing';
    Http::fake(function (Request $request) use (&$uploadStatus, &$processingStatus) {
        if (str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')) {
            return Http::response(youtubeChannelList());
        }
        if (str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/videos?')) {
            return Http::response(['items' => [youtubeVideoResource(YT_CHANNEL, YT_VIDEO, $uploadStatus, $processingStatus, 'private')]]);
        }

        return Http::response([], 404);
    });
    expect($adapter->lookupStatus($remote)?->status)->toBe('pending');
    $uploadStatus = 'processed';
    $processingStatus = 'succeeded';
    expect($adapter->lookupStatus($remote)?->status)->toBe('published');
});

it('rejects cross-channel uploads, ambiguous channel identities and unverified long uploads', function () {
    [, , , , , , , , $adapter, $request] = youtubePublishingContext();
    Http::preventStrayRequests();
    $wrongChannel = new PublishingAdapterRequest(
        network: $request->network, destinationExternalId: 'UCzyxwvutsrqponmlkjihgf',
        content: $request->content, media: $request->media, scheduledAt: $request->scheduledAt,
        idempotencyKey: $request->idempotencyKey, correlationId: $request->correlationId,
        integrationConnectionId: $request->integrationConnectionId, credentialReference: $request->credentialReference,
        settings: $request->settings, publishingJobId: $request->publishingJobId,
    );
    expect(fn () => $adapter->publish($wrongChannel))->toThrow(PublishingAdapterException::class, 'authenticated channel');

    [, , , , , , , , $longAdapter, $longRequest] = youtubePublishingContext();
    $media = $longRequest->media;
    $media[0]['duration_seconds'] = 1200;
    $media[0]['metadata']['duration_seconds'] = 1200;
    $longRequest = new PublishingAdapterRequest(
        network: $longRequest->network, destinationExternalId: $longRequest->destinationExternalId,
        content: $longRequest->content, media: $media, scheduledAt: $longRequest->scheduledAt,
        idempotencyKey: $longRequest->idempotencyKey, correlationId: $longRequest->correlationId,
        integrationConnectionId: $longRequest->integrationConnectionId, credentialReference: $longRequest->credentialReference,
        settings: $longRequest->settings, publishingJobId: $longRequest->publishingJobId,
    );
    Http::fake(['https://www.googleapis.com/youtube/v3/channels*' => Http::response(youtubeChannelList(YT_CHANNEL, 'eligible'))]);
    expect(fn () => $longAdapter->publish($longRequest))->toThrow(PublishingAdapterException::class, 'enable long uploads');
});

it('rejects invalid metadata and unsupported media before submitting an upload', function () {
    [, , , , , , , , $adapter, $request] = youtubePublishingContext(['title' => str_repeat('x', 101)]);
    Http::preventStrayRequests();
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'title');

    [, , , , , , , , $adapter, $request] = youtubePublishingContext(['privacy_status' => 'everyone']);
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'privacy status');

    [, , , , , , , , $adapter, $request] = youtubePublishingContext();
    $media = $request->media;
    $media[0]['mime_type'] = 'image/png';
    $invalidRequest = new PublishingAdapterRequest(
        network: $request->network, destinationExternalId: $request->destinationExternalId,
        content: $request->content, media: $media, scheduledAt: $request->scheduledAt,
        idempotencyKey: $request->idempotencyKey, correlationId: $request->correlationId,
        integrationConnectionId: $request->integrationConnectionId, credentialReference: $request->credentialReference,
        settings: $request->settings, publishingJobId: $request->publishingJobId,
    );
    expect(fn () => $adapter->publish($invalidRequest))->toThrow(PublishingAdapterException::class, 'video MIME type');
});

it('reconciles the persisted publication after YouTube confirms processing success', function () {
    [, , , , , , $publication, $job] = youtubePublishingContext();
    config(['publishing.adapters.youtube.enabled' => true]);
    $job->setAdapterState(['requested_privacy_status' => 'private'])
        ->forceFill(['external_id' => YT_VIDEO, 'external_url' => 'https://www.youtube.com/watch?v='.YT_VIDEO])
        ->succeed()
        ->save();
    $publication->markSubmitted(YT_VIDEO, 'https://www.youtube.com/watch?v='.YT_VIDEO)->save();

    Http::fake(function (Request $request) {
        if (str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')) {
            return Http::response(youtubeChannelList());
        }
        if (str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/videos?')) {
            return Http::response(['items' => [youtubeVideoResource(YT_CHANNEL, YT_VIDEO, 'processed', 'succeeded', 'private')]]);
        }

        return Http::response([], 404);
    });

    expect(app(PublishingService::class)->reconcileStatus($publication->refresh()))->toBe('published')
        ->and($publication->refresh()->status)->toBe(Publication::STATUS_SUCCEEDED)
        ->and($job->refresh()->status)->toBe(PublishingJob::STATUS_SUCCEEDED);
});

it('fails closed when the stored Google grant lacks the required read-only scope', function () {
    [, , , , , $connection, , , $adapter, $request] = youtubePublishingContext();
    app(SocialCredentialStore::class)->update(
        app(SocialCredentialStore::class)->forConnection($connection),
        new SocialTokenSet(
            accessToken: 'youtube-user-access-token',
            refreshToken: 'youtube-refresh-token',
            accessTokenExpiresAt: CarbonImmutable::now()->addHour(),
            refreshTokenExpiresAt: null,
            scopes: ['https://www.googleapis.com/auth/youtube.upload'],
        ),
    );
    Http::preventStrayRequests();
    expect(fn () => $adapter->publish($request))->toThrow(PublishingAdapterException::class, 'missing a required YouTube upload or read-only scope');
});

it('retains an encrypted resumable session after an interrupted chunk and does not restart the upload', function () {
    [, , , , , , , $job, $adapter, $request] = youtubePublishingContext();
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=interrupted-session-secret';
    $sessionCreates = 0;
    Http::fake(function (Request $httpRequest) use ($session, &$sessionCreates) {
        if (str_starts_with($httpRequest->url(), 'https://www.googleapis.com/youtube/v3/channels')) {
            return Http::response(youtubeChannelList());
        }
        if ($httpRequest->method() === 'POST' && str_contains($httpRequest->url(), '/upload/youtube/v3/videos')) {
            $sessionCreates++;

            return Http::response('', 200, ['Location' => $session]);
        }
        if ($httpRequest->method() === 'PUT' && $httpRequest->url() === $session) {
            if (str_starts_with((string) ($httpRequest->header('Content-Range')[0] ?? ''), 'bytes */')) {
                return Http::response('', 308);
            }

            return Http::response(['error' => ['message' => 'temporary provider failure']], 500);
        }

        return Http::response([], 404);
    });

    try {
        $adapter->publish($request);
        test()->fail('Expected the resumable upload to remain retryable.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->retryable)->toBeTrue();
    }

    $job->refresh();
    expect($sessionCreates)->toBe(1)
        ->and($job->adapterState()['session_uri'])->toBe($session)
        ->and($job->getRawOriginal('adapter_state_encrypted'))->not->toContain('interrupted-session-secret');
});

it('classifies YouTube quota errors without exposing provider response bodies', function () {
    [, , , , , , , , $adapter, $request] = youtubePublishingContext();
    Http::fake(function (Request $httpRequest) {
        if (str_starts_with($httpRequest->url(), 'https://www.googleapis.com/youtube/v3/channels')) {
            return Http::response(youtubeChannelList());
        }
        if ($httpRequest->method() === 'POST' && str_contains($httpRequest->url(), '/upload/youtube/v3/videos')) {
            return Http::response(['error' => ['message' => 'private quota details', 'errors' => [['reason' => 'quotaExceeded']]]], 403);
        }

        return Http::response([], 404);
    });

    try {
        $adapter->publish($request);
        test()->fail('Expected quota error.');
    } catch (PublishingAdapterException $exception) {
        expect($exception->failureCode)->toBe('quota_exceeded')->and($exception->getMessage())->not->toContain('private quota details');
    }
});