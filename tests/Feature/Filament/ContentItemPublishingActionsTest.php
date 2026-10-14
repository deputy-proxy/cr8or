<?php

use App\Filament\Resources\ContentItems\Pages\ListContentItems;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\PublishingService;
use Livewire\Livewire;
use Tests\Support\Publishing\FakeFacebookPublishingAdapter;

beforeEach(function (): void {
    $this->app->singleton(FakeFacebookPublishingAdapter::class);

    config(['publishing.adapters.facebook' => [
        'key' => 'facebook',
        'class' => FakeFacebookPublishingAdapter::class,
        'enabled' => true,
        'native_scheduling' => false,
    ]]);
});

/** @return array{Organization, User, Enterprise, ContentItem, Channel, SocialAccount, FakeFacebookPublishingAdapter} */
function filamentFacebookPublishingContext(string $contentStatus = ContentItem::STATUS_APPROVED): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'marketing_strategy_id' => $strategy->getKey(),
    ]);
    $channel = Channel::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'facebook',
        'status' => Channel::STATUS_ACTIVE,
    ]);
    $content = ContentItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'campaign_id' => $campaign->getKey(),
        'channel_id' => $channel->getKey(),
        'status' => $contentStatus,
        'title' => 'Facebook publishing test',
        'body' => 'A single test post from CR8OR.',
    ]);

    $identity = SocialIdentity::factory()->create([
        'organization_id' => $organization->getKey(),
        'provider' => 'meta',
        'external_id' => 'meta-identity-'.$enterprise->getKey(),
    ]);
    IntegrationConnection::query()->create([
        'organization_id' => $organization->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'social_identity_id' => $identity->getKey(),
        'provider' => 'meta',
        'external_account_id' => $identity->external_id,
        'credential_reference' => 'social:test-meta-'.$enterprise->getKey(),
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $account = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'channel_id' => $channel->getKey(),
        'social_identity_id' => $identity->getKey(),
        'provider' => 'facebook',
        'name' => 'Test Facebook Page',
        'external_id' => 'facebook-page-'.$enterprise->getKey(),
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);

    return [$organization, $user, $enterprise, $content, $channel, $account, app(FakeFacebookPublishingAdapter::class)];
}

it('lets an authorized enterprise owner mark approved content publication-ready from Filament', function (): void {
    [, $user, , $content] = filamentFacebookPublishingContext();
    $this->actingAs($user);

    Livewire::test(ListContentItems::class)
        ->assertTableActionVisible('mark_publication_ready', $content)
        ->callTableAction('mark_publication_ready', $content);

    expect($content->refresh()->status)->toBe(ContentItem::STATUS_PUBLICATION_READY);
});

it('publishes publication-ready content to the selected connected Facebook account from Filament', function (): void {
    [, $user, , $content, , $account, $adapter] = filamentFacebookPublishingContext(ContentItem::STATUS_PUBLICATION_READY);
    $this->actingAs($user);

    Livewire::test(ListContentItems::class)
        ->assertTableActionVisible('publish_now', $content)
        ->callTableAction('publish_now', $content, ['social_account_id' => $account->getKey()]);

    $publication = Publication::query()->where('content_item_id', $content->getKey())->firstOrFail();
    expect($publication->social_account_id)->toBe($account->getKey())
        ->and($publication->status)->toBe(Publication::STATUS_SUBMITTED)
        ->and($publication->external_id)->not->toBeNull()
        ->and($publication->external_url)->toContain('facebook.example/posts/')
        ->and($publication->results()->count())->toBe(1)
        ->and($adapter->publishCalls)->toBe(1);
});

it('does not create a duplicate publication when the manual publish action is repeated', function (): void {
    [, $user, , $content, , $account, $adapter] = filamentFacebookPublishingContext(ContentItem::STATUS_PUBLICATION_READY);
    $service = app(PublishingService::class);

    $first = $service->publishNow($user, $content, $account);
    $second = $service->publishNow($user, $content->refresh(), $account->refresh());

    expect($second->getKey())->toBe($first->getKey())
        ->and(Publication::query()->where('content_item_id', $content->getKey())->count())->toBe(1)
        ->and($adapter->publishCalls)->toBe(1);
});

it('does not blindly retry a publication with an ambiguous provider outcome', function (): void {
    [, $user, , $content, , $account, $adapter] = filamentFacebookPublishingContext(ContentItem::STATUS_PUBLICATION_READY);
    $adapter->shouldTimeout = true;
    $adapter->acceptBeforeTimeout = true;
    $service = app(PublishingService::class);

    expect(fn () => $service->publishNow($user, $content, $account))
        ->toThrow(\App\Exceptions\PublishingAdapterException::class);

    $publication = Publication::query()->where('content_item_id', $content->getKey())->firstOrFail();
    expect($publication->status)->toBe(Publication::STATUS_UNKNOWN);

    expect(fn () => $service->publishNow($user, $content->refresh(), $account->refresh()))
        ->toThrow(LogicException::class, 'ambiguous outcome');

    expect($adapter->publishCalls)->toBe(1)
        ->and(Publication::query()->where('content_item_id', $content->getKey())->count())->toBe(1);
});

it('rejects a manual publication account that does not match the content channel', function (): void {
    [, $user, $enterprise, $content, , $account] = filamentFacebookPublishingContext(ContentItem::STATUS_PUBLICATION_READY);
    $otherChannel = Channel::factory()->create(['enterprise_id' => $enterprise->getKey(), 'type' => 'facebook']);
    $account->update(['channel_id' => $otherChannel->getKey()]);

    expect(fn () => app(PublishingService::class)->publishNow($user, $content, $account))
        ->toThrow(LogicException::class, 'must match the content item channel');
});

it('does not allow a non-manager to mark content publication-ready', function (): void {
    [, , $enterprise, $content] = filamentFacebookPublishingContext();
    $viewer = User::factory()->create();
    Membership::factory()->create([
        'role' => App\Enums\MembershipRole::Member,
        'user_id' => $viewer->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    expect(fn () => app(ContentItemService::class)->markPublicationReadyManually($viewer, $content))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($content->refresh()->status)->toBe(ContentItem::STATUS_APPROVED);
});

it('does not submit immediately when a future publication is already scheduled', function (): void {
    [, $user, , $content, , $account, $adapter] = filamentFacebookPublishingContext(ContentItem::STATUS_PUBLICATION_READY);
    app(PublishingService::class)->schedule($user, $content, $account, now()->addHour());

    expect(fn () => app(PublishingService::class)->publishNow($user, $content->refresh(), $account->refresh()))
        ->toThrow(LogicException::class, 'future publication scheduled');

    expect($adapter->publishCalls)->toBe(0)
        ->and(Publication::query()->where('content_item_id', $content->getKey())->count())->toBe(1);
});

it('does not allow a non-manager to publish content from the service boundary', function (): void {
    [, , $enterprise, $content, , $account, $adapter] = filamentFacebookPublishingContext(ContentItem::STATUS_PUBLICATION_READY);
    $viewer = User::factory()->create();
    Membership::factory()->create([
        'role' => App\Enums\MembershipRole::Member,
        'user_id' => $viewer->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    expect(fn () => app(PublishingService::class)->publishNow($viewer, $content, $account))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($adapter->publishCalls)->toBe(0)
        ->and(Publication::query()->where('content_item_id', $content->getKey())->count())->toBe(0);
});