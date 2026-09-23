<?php

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('builds the marketing hierarchy without duplicating relationship state', function () {
    $enterprise = Enterprise::factory()->create();
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);
    $series = ContentSeries::factory()->create(['campaign_id' => $campaign]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise]);
    $audience = Audience::factory()->create(['enterprise_id' => $enterprise]);
    $item = ContentItem::factory()->forSeries($series)->forChannel($channel)->forAudience($audience)->create();
    $script = Script::factory()->create(['content_item_id' => $item]);

    expect($campaign->marketingStrategy->is($strategy))->toBeTrue()
        ->and($strategy->campaigns->contains($campaign))->toBeTrue()
        ->and($series->campaign->is($campaign))->toBeTrue()
        ->and($campaign->contentSeries->contains($series))->toBeTrue()
        ->and($item->campaign->is($campaign))->toBeTrue()
        ->and($item->contentSeries->is($series))->toBeTrue()
        ->and($item->channel->is($channel))->toBeTrue()
        ->and($item->audience->is($audience))->toBeTrue()
        ->and($item->scripts->contains($script))->toBeTrue();
});

it('rejects cross-enterprise marketing relationships', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);

    expect(fn () => Campaign::create([
        'enterprise_id' => $otherEnterprise->id,
        'marketing_strategy_id' => $strategy->id,
        'name' => 'Foreign campaign',
        'status' => Campaign::STATUS_DRAFT,
    ]))->toThrow(LogicException::class);

    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);
    $channel = Channel::factory()->create(['enterprise_id' => $otherEnterprise]);

    expect(fn () => ContentItem::factory()->forCampaign($campaign)->forChannel($channel)->create())
        ->toThrow(LogicException::class);
});

it('protects enterprise ownership and approved content relationships', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);
    $otherCampaign = Campaign::factory()->create([
        'enterprise_id' => $otherEnterprise,
        'marketing_strategy_id' => MarketingStrategy::factory()->create(['enterprise_id' => $otherEnterprise]),
    ]);
    $item = ContentItem::factory()->forCampaign($campaign)->create(['status' => ContentItem::STATUS_APPROVED]);

    expect(fn () => $strategy->update(['enterprise_id' => $otherEnterprise->id]))->toThrow(LogicException::class)
        ->and(fn () => $campaign->update(['enterprise_id' => $otherEnterprise->id]))->toThrow(LogicException::class)
        ->and(fn () => $item->update(['campaign_id' => $otherCampaign->id]))->toThrow(LogicException::class);
});

it('enforces content lifecycle transitions at the model boundary', function () {
    $item = ContentItem::factory()->create();

    expect(fn () => $item->transitionTo(ContentItem::STATUS_PUBLICATION_READY))->toThrow(LogicException::class);

    $item->transitionTo(ContentItem::STATUS_IN_REVIEW)
        ->transitionTo(ContentItem::STATUS_APPROVED)
        ->transitionTo(ContentItem::STATUS_PUBLICATION_READY);

    expect($item->status)->toBe(ContentItem::STATUS_PUBLICATION_READY)
        ->and(fn () => $item->transitionTo(ContentItem::STATUS_DRAFT))->toThrow(LogicException::class);
});

it('enforces the enterprise organization boundary through marketing policies', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $foreignStrategy = MarketingStrategy::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);
    $foreignCampaign = Campaign::factory()->create(['enterprise_id' => $foreignEnterprise, 'marketing_strategy_id' => $foreignStrategy]);
    $item = ContentItem::factory()->forCampaign($campaign)->create();
    $foreignItem = ContentItem::factory()->forCampaign($foreignCampaign)->create();

    expect(Gate::forUser($owner)->allows('view', $strategy))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $campaign))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $item))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $foreignStrategy))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignCampaign))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignItem))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [MarketingStrategy::class, $enterprise]))->toBeTrue();
});

it('keeps the marketing schema explicit and non-polymorphic', function () {
    expect(Schema::getColumnListing('marketing_strategies'))->toBe([
        'id', 'enterprise_id', 'name', 'description', 'status', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('campaigns'))->toBe([
        'id', 'enterprise_id', 'marketing_strategy_id', 'name', 'description', 'status', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('content_series'))->toBe([
        'id', 'campaign_id', 'name', 'description', 'status', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('scripts'))->toBe([
        'id', 'content_item_id', 'title', 'body', 'created_at', 'updated_at',
    ]);
});
