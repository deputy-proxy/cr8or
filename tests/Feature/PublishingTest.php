<?php

use App\Data\PublishingAdapterResult;
use App\Exceptions\PublishingAdapterException;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\SocialAccount;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Providers\FakePublishingAdapter;
use App\Services\ApprovalRequestService;
use App\Services\McpCapabilityAuthorizer;
use App\Services\PublishingService;
use Tests\Support\Publishing\FakeInstagramPublishingAdapter;
use Tests\Support\Publishing\FakeLinkedInPublishingAdapter;

function publishingContext(): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $org->id]);
    $strategy = \App\Models\MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->id, 'marketing_strategy_id' => $strategy->id]);
    $campaign->enterprise_id = $enterprise->id;
    $campaign->save();
    $content = ContentItem::factory()->create(['status' => ContentItem::STATUS_PUBLICATION_READY, 'enterprise_id' => $enterprise->id, 'campaign_id' => $campaign->id]);
    $content->enterprise_id = $enterprise->id;
    $content->campaign_id = $campaign->id;
    $content->save();
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'type' => 'linkedin']);
    $identity = SocialIdentity::factory()->create([
        'organization_id' => $org->id,
        'provider' => 'linkedin',
        'external_id' => 'test-linkedin-identity-'.$enterprise->id,
    ]);
    IntegrationConnection::query()->create([
        'organization_id' => $org->id,
        'enterprise_id' => $enterprise->id,
        'social_identity_id' => $identity->id,
        'provider' => 'linkedin',
        'external_account_id' => $identity->external_id,
        'credential_reference' => 'social:test-linkedin-'.$enterprise->id,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $account = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => 'linkedin',
    ]);

    return [$org, $user, $enterprise, $content, $channel, $account];
}

function nativePublishingContext(string $network): array
{
    [$organization, $user, $enterprise, $content, $oldChannel, $oldAccount] = publishingContext();
    $oldAccount->delete();

    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'type' => $network]);
    $identityProvider = $network === 'instagram' ? 'meta' : $network;
    $identity = SocialIdentity::factory()->create([
        'organization_id' => $organization->id,
        'provider' => $identityProvider,
        'external_id' => 'identity-'.$network.'-'.$enterprise->id,
    ]);
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'social_identity_id' => $identity->id,
        'provider' => $identityProvider,
        'external_account_id' => $identity->external_id,
        'credential_reference' => 'social:test-'.$network,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $account = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => $network,
        'name' => 'Test '.$network,
        'external_id' => 'destination-'.$network,
    ]);

    return [$organization, $user, $enterprise, $content, $channel, $account, $identity, $connection];
}

it('requires publication-ready content and same-enterprise active accounts', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    expect($publication->status)->toBe(Publication::STATUS_SCHEDULED)->and($publication->schedule)->not->toBeNull();

    $draft = ContentItem::factory()->forCampaign($content->campaign)->create();
    expect(fn () => app(PublishingService::class)->schedule($user, $draft, $account, now()->addHour()))->toThrow(LogicException::class);

    $foreign = Enterprise::factory()->create();
    $foreignChannel = Channel::factory()->create(['enterprise_id' => $foreign->id]);
    $foreignAccount = SocialAccount::factory()->create(['enterprise_id' => $foreign->id, 'channel_id' => $foreignChannel->id]);
    expect(fn () => app(PublishingService::class)->schedule($user, $content, $foreignAccount, now()->addHour()))->toThrow(LogicException::class);
});

it('requires matching approval for an Agent publication capability', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(\App\Agents\MarketingAgent::class)->create(['slug' => 'marketing']);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->id]);
    \App\Models\ExpertDescriptor::query()->updateOrCreate(['slug' => 'marketing'], ['runtime_class' => \App\Experts\MarketingExpert::class, 'enabled' => true]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $user->id, 'actor_name' => $user->name]);

    expect(fn () => app(PublishingService::class)->schedule(
        $user, $content, $account, now()->addHour(), null, $assignment, $execution,
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $approval = app(ApprovalRequestService::class)->request($user, 'publication.publish', $assignment, $execution, ['content_item_id' => $content->id], null, 'marketing');
    $approver = User::factory()->create();
    Membership::factory()->admin()->create(['user_id' => $approver->id, 'organization_id' => $org->id]);
    app(ApprovalRequestService::class)->approve($approval, $approver);

    $p = app(PublishingService::class)->schedule(
        $user, $content, $account, now()->addHour(), $approval, $assignment, $execution,
    );
    expect($p->approval_request_id)->toBe($approval->id);
});

it('submits through the selected network adapter and correlates result', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    $p = app(PublishingService::class)->submit($user, $p);
    expect($p->status)->toBe(Publication::STATUS_SUBMITTED)->and($p->external_id)->not->toBeNull()->and($p->results()->count())->toBe(1);
});

it('reconciles a submitted publication and preserves result history', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    $p = app(PublishingService::class)->submit($user, $p);
    $p = app(PublishingService::class)->reconcile($p, new PublishingAdapterResult(
        $p->external_id, $p->external_url, 'succeeded', ['source' => 'reconciliation'],
    ));
    expect($p->status)->toBe(Publication::STATUS_SUCCEEDED)->and($p->results()->count())->toBe(2);
});

it('records provider timeouts explicitly', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $provider = app(FakePublishingAdapter::class);
    expect($provider)->toBeInstanceOf(FakePublishingAdapter::class);
    $provider->shouldTimeout = true;
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    expect(fn () => app(PublishingService::class)->submit($user, $p))->toThrow(PublishingAdapterException::class);
    expect($p->refresh()->status)->toBe(Publication::STATUS_UNKNOWN)->and($p->failure_code)->toBe('external.timeout');
});

it('retries a failed publication idempotently', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $provider = app(FakePublishingAdapter::class);
    expect($provider)->toBeInstanceOf(FakePublishingAdapter::class);
    $provider->shouldRateLimit = true;
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    expect(fn () => app(PublishingService::class)->submit($user, $p))->toThrow(PublishingAdapterException::class);
    $provider->shouldRateLimit = false;
    $p = app(PublishingService::class)->submit($user, $p->refresh());
    expect($p->id)->toBeGreaterThan(0)->and(Publication::query()->count())->toBe(1)->and($p->publishingJobs()->first()->attempts)->toBe(2);
});

it('does not allow direct publication status mutation to bypass the lifecycle service', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    $publication->status = Publication::STATUS_SUCCEEDED;
    expect(fn () => $publication->save())->toThrow(LogicException::class);
});

it('keeps publication results immutable and organization scoped', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    app(PublishingService::class)->submit($user, $p);
    $r = $p->refresh()->results()->firstOrFail();
    $r->provider_status = 'tampered';
    expect(fn () => $r->save())->toThrow(LogicException::class);
    expect(PublicationResult::query()->where('publication_id', $p->id)->count())->toBe(1);
});

it('requires Expert provenance and approval for MCP publication authorization', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(\App\Agents\MarketingAgent::class)->create(['slug' => 'marketing']);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->id]);
    \App\Models\ExpertDescriptor::query()->updateOrCreate(['slug' => 'marketing'], ['runtime_class' => \App\Experts\MarketingExpert::class, 'enabled' => true]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $user->id]);

    expect(fn () => app(McpCapabilityAuthorizer::class)->authorizeMutation(
        $user, 'publication.publish', $enterprise, $assignment->id, $execution->id, null,
        ['content_item_id' => $content->id], ['update', $content], 'marketing',
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('resolves multiple network adapters and records adapter and destination separately', function () {
    config([
        'publishing.adapters.linkedin' => [
            'key' => 'linkedin',
            'class' => FakeLinkedInPublishingAdapter::class,
            'enabled' => true,
        ],
        'publishing.adapters.instagram' => [
            'key' => 'instagram',
            'class' => FakeInstagramPublishingAdapter::class,
            'enabled' => true,
        ],
    ]);

    foreach (['linkedin', 'instagram'] as $network) {
        [, $user, , $content, , $account] = nativePublishingContext($network);
        $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());
        $publication = app(PublishingService::class)->submit($user, $publication);
        $job = $publication->publishingJobs()->sole();
        $result = $publication->results()->sole();

        expect($publication->status)->toBe(Publication::STATUS_SUBMITTED)
            ->and($job->adapter_key)->toBe($network)
            ->and($job->network)->toBe($network)
            ->and($job->destination_external_id)->toBe('destination-'.$network)
            ->and($result->provider)->toBe($network)
            ->and($result->network)->toBe($network)
            ->and($result->destination_external_id)->toBe('destination-'.$network)
            ->and($result->correlation_id)->toBe($publication->correlation_id);
    }
});

it('fails closed when the configured adapter does not support the requested network', function () {
    config(['publishing.adapters.linkedin' => [
        'key' => 'wrong-adapter',
        'class' => FakeInstagramPublishingAdapter::class,
        'enabled' => true,
    ]]);
    [, $user, , $content, , $account] = nativePublishingContext('linkedin');

    expect(fn () => app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute()))
        ->toThrow(LogicException::class);

    expect(Publication::query()->count())->toBe(0);
});

it('rejects disabled networks before creating a publication', function () {
    config(['publishing.adapters.linkedin.enabled' => false]);
    [, $user, , $content, , $account] = publishingContext();

    expect(fn () => app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute()))
        ->toThrow(PublishingAdapterException::class);

    expect(Publication::query()->count())->toBe(0);
});

it('rejects a native destination whose authorization connection is disabled before submission', function () {
    config(['publishing.adapters.linkedin' => [
        'key' => 'linkedin',
        'class' => FakeLinkedInPublishingAdapter::class,
        'enabled' => true,
    ]]);
    [, $user, , $content, , $account, , $connection] = nativePublishingContext('linkedin');
    $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(LogicException::class);

    expect($publication->publishingJobs()->count())->toBe(0)
        ->and($publication->results()->count())->toBe(0);
});

it('blocks retries after an ambiguous timeout until reconciliation', function () {
    [, $user, , $content, , $account] = publishingContext();
    $adapter = app(FakePublishingAdapter::class);
    $adapter->shouldTimeout = true;
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(PublishingAdapterException::class);

    $adapter->shouldTimeout = false;
    expect($publication->refresh()->status)->toBe(Publication::STATUS_UNKNOWN)
        ->and(fn () => app(PublishingService::class)->submit($user, $publication))->toThrow(LogicException::class)
        ->and($publication->publishingJobs()->sole()->attempts)->toBe(1)
        ->and($publication->results()->count())->toBe(1);
});

it('reconciles remote acceptance after a lost response without resubmitting', function () {
    [, $user, , $content, , $account] = publishingContext();
    $adapter = app(FakePublishingAdapter::class);
    $adapter->shouldTimeout = true;
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(PublishingAdapterException::class);

    $publication = app(PublishingService::class)->reconcile($publication, new PublishingAdapterResult(
        'remote-accepted-id',
        'https://linkedin.example/posts/remote-accepted-id',
        'accepted',
        ['source' => 'remote_lookup'],
    ));

    expect($publication->status)->toBe(Publication::STATUS_SUBMITTED)
        ->and($publication->external_id)->toBe('remote-accepted-id')
        ->and($publication->publishingJobs()->sole()->status)->toBe(\App\Models\PublishingJob::STATUS_SUCCEEDED)
        ->and($publication->results()->count())->toBe(2);

    expect(app(PublishingService::class)->submit($user, $publication)->refresh()->status)
        ->toBe(Publication::STATUS_SUBMITTED)
        ->and($publication->publishingJobs()->sole()->attempts)->toBe(1);
});

it('does not let delayed failure results overwrite a terminal success', function () {
    [, $user, , $content, , $account] = publishingContext();
    $adapter = app(FakePublishingAdapter::class);
    $adapter->shouldTimeout = true;
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(PublishingAdapterException::class);

    $publication = app(PublishingService::class)->reconcile($publication, new PublishingAdapterResult(
        'remote-published-id', null, 'succeeded',
    ));
    $resultCount = $publication->results()->count();
    $publication = app(PublishingService::class)->reconcile($publication, new PublishingAdapterResult(
        'remote-published-id', null, 'failed',
    ));

    expect($publication->status)->toBe(Publication::STATUS_SUCCEEDED)
        ->and($publication->results()->count())->toBe($resultCount);
});

it('allows a rate-limited submission to retry with the same idempotency key', function () {
    [, $user, , $content, , $account] = publishingContext();
    $adapter = app(FakePublishingAdapter::class);
    $adapter->shouldRateLimit = true;
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(PublishingAdapterException::class);
    $job = $publication->publishingJobs()->sole();
    $key = $job->idempotency_key;

    $adapter->shouldRateLimit = false;
    $publication = app(PublishingService::class)->submit($user, $publication->refresh());

    expect($publication->status)->toBe(Publication::STATUS_SUBMITTED)
        ->and($publication->publishingJobs()->sole()->idempotency_key)->toBe($key)
        ->and($publication->publishingJobs()->sole()->attempts)->toBe(2)
        ->and($publication->results()->count())->toBe(2);
});

it('does not submit a future publication unless native scheduling is explicitly enabled', function () {
    config(['publishing.adapters.linkedin.native_scheduling' => false]);
    [, $user, , $content, , $account] = publishingContext();
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(LogicException::class);

    expect($publication->publishingJobs()->count())->toBe(0)
        ->and($publication->results()->count())->toBe(0);
});

it('redacts credential-shaped fields from persisted adapter payloads', function () {
    [, $user, , $content, , $account] = publishingContext();
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());
    $publication = app(PublishingService::class)->submit($user, $publication);
    $publication = app(PublishingService::class)->reconcile($publication, new PublishingAdapterResult(
        $publication->external_id,
        $publication->external_url,
        'succeeded',
        ['safe_value' => 'retained', 'access_token' => 'do-not-persist', 'nested' => ['refreshToken' => 'do-not-persist', 'count' => 1]],
    ));

    $result = $publication->results()->latest('id')->firstOrFail();
    expect($result->payload)->toHaveKey('safe_value', 'retained')
        ->and($result->payload)->not->toHaveKey('access_token')
        ->and($result->payload['nested'])->not->toHaveKey('refreshToken')
        ->and($result->payload['nested'])->toHaveKey('count', 1);
});

it('keeps historical adapter attribution readable when destination context is absent', function () {
    [, $user, $enterprise, $content, , $account] = publishingContext();
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());
    $historical = PublicationResult::query()->create([
        'enterprise_id' => $enterprise->id,
        'publication_id' => $publication->id,
        'provider' => 'postiz',
        'provider_status' => 'submitted',
        'external_id' => 'historical-publication-1',
        'correlation_id' => 'historical-correlation-1',
        'recorded_at' => now(),
    ]);

    expect($historical->fresh()->provider)->toBe('postiz')
        ->and($historical->fresh()->network)->toBeNull()
        ->and($historical->fresh()->destination_external_id)->toBeNull();
});

it('rejects unsupported media before creating a publication', function () {
    [, $user, $enterprise, $content, , $account] = publishingContext();
    $asset = Asset::factory()->create([
        'enterprise_id' => $enterprise->id,
        'content_item_id' => $content->id,
        'status' => Asset::STATUS_ACTIVE,
    ]);
    AssetVersion::factory()->create([
        'asset_id' => $asset->id,
        'mime_type' => 'application/pdf',
    ]);

    expect(fn () => app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute()))
        ->toThrow(PublishingAdapterException::class);

    expect(Publication::query()->count())->toBe(0);
});

it('does not retry a definitive non-retryable rejection', function () {
    [, $user, , $content, , $account] = publishingContext();
    $adapter = app(FakePublishingAdapter::class);
    $adapter->shouldFail = true;
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(PublishingAdapterException::class);
    $adapter->shouldFail = false;

    expect(fn () => app(PublishingService::class)->submit($user, $publication->refresh()))
        ->toThrow(LogicException::class);

    expect($publication->publishingJobs()->sole()->attempts)->toBe(1)
        ->and($publication->results()->count())->toBe(1);
});

it('requires social reauthorization when an adapter reports expired credentials', function () {
    [, $user, , $content, , $account, , $connection] = nativePublishingContext('linkedin');
    config(['publishing.adapters.linkedin' => [
        'key' => 'linkedin',
        'class' => FakePublishingAdapter::class,
        'enabled' => true,
        'native_scheduling' => true,
    ]]);
    $adapter = app(FakePublishingAdapter::class);
    $adapter->shouldRequireAuthentication = true;
    $publication = app(PublishingService::class)->schedule($user, $content, $account, now()->subMinute());

    expect(fn () => app(PublishingService::class)->submit($user, $publication))
        ->toThrow(PublishingAdapterException::class);

    expect($connection->refresh()->status)->toBe(IntegrationConnection::STATUS_RECONNECT_REQUIRED)
        ->and($publication->refresh()->status)->toBe(Publication::STATUS_FAILED)
        ->and($publication->publishingJobs()->sole()->retryable)->toBeFalse();
});