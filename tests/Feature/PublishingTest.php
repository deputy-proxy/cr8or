<?php

use App\Contracts\PublishingProvider;
use App\Data\PublishingProviderResult;
use App\Exceptions\PublishingProviderException;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\SocialAccount;
use App\Models\User;
use App\Providers\FakePublishingProvider;
use App\Services\ApprovalRequestService;
use App\Services\McpCapabilityAuthorizer;
use App\Services\PublishingService;

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
    $account = SocialAccount::factory()->create(['enterprise_id' => $enterprise->id, 'channel_id' => $channel->id]);

    return [$org, $user, $enterprise, $content, $channel, $account];
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
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $user->id, 'actor_name' => $user->name]);
    AgentPermission::factory()->requiresApproval()->create(['agent_assignment_id' => $assignment->id, 'capability' => 'publication.publish']);

    expect(fn () => app(PublishingService::class)->schedule(
        $user,
        $content,
        $account,
        now()->addHour(),
        null,
        $assignment,
        $execution,
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $approval = app(ApprovalRequestService::class)->request($user, 'publication.publish', $assignment, $execution, ['content_item_id' => $content->id]);
    $approver = User::factory()->create();
    Membership::factory()->admin()->create(['user_id' => $approver->id, 'organization_id' => $org->id]);
    app(ApprovalRequestService::class)->approve($approval, $approver);

    $p = app(PublishingService::class)->schedule(
        $user,
        $content,
        $account,
        now()->addHour(),
        $approval,
        $assignment,
        $execution,
    );
    expect($p->approval_request_id)->toBe($approval->id);
});

it('submits through Postiz boundary and correlates result', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    $p = app(PublishingService::class)->submit($user, $p);
    expect($p->status)->toBe(Publication::STATUS_SUBMITTED)->and($p->external_id)->not->toBeNull()->and($p->results()->count())->toBe(1);
});

it('reconciles a submitted publication and preserves result history', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    $p = app(PublishingService::class)->submit($user, $p);
    $p = app(PublishingService::class)->reconcile($p, new PublishingProviderResult(
        $p->external_id,
        $p->external_url,
        'succeeded',
        ['source' => 'reconciliation'],
    ));
    expect($p->status)->toBe(Publication::STATUS_SUCCEEDED)->and($p->results()->count())->toBe(2);
});

it('records provider timeouts explicitly', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $provider = app(PublishingProvider::class);
    expect($provider)->toBeInstanceOf(FakePublishingProvider::class);
    $provider->shouldTimeout = true;
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    expect(fn () => app(PublishingService::class)->submit($user, $p))->toThrow(PublishingProviderException::class);
    expect($p->refresh()->status)->toBe(Publication::STATUS_FAILED)->and($p->failure_code)->toBe('timeout');
});

it('retries a failed publication idempotently', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $provider = app(PublishingProvider::class);
    expect($provider)->toBeInstanceOf(FakePublishingProvider::class);
    $provider->shouldFail = true;
    $p = app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());
    expect(fn () => app(PublishingService::class)->submit($user, $p))->toThrow(PublishingProviderException::class);
    $provider->shouldFail = false;
    $p = app(PublishingService::class)->submit($user, $p->refresh());
    expect($p->id)->toBeGreaterThan(0)
        ->and(Publication::query()->count())->toBe(1)
        ->and($p->publishingJobs()->first()->attempts)->toBe(2);
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

it('uses the same server-side capability boundary for MCP publication authorization', function () {
    [$org, $user, $enterprise, $content, $channel, $account] = publishingContext();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $user->id]);
    AgentPermission::factory()->create(['agent_assignment_id' => $assignment->id, 'capability' => 'publication.publish']);

    expect(app(McpCapabilityAuthorizer::class)->authorizeMutation($user, 'publication.publish', $enterprise, $assignment->id, $execution->id, null, ['content_item_id' => $content->id], ['update', $content]))->toBeNull();
});
