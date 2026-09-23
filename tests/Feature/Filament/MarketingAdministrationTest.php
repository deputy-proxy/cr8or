<?php

use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\ContentSeries\ContentSeriesResource;
use App\Filament\Resources\MarketingStrategies\MarketingStrategyResource;
use App\Filament\Resources\Scripts\ScriptResource;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('scopes marketing resources to the authenticated users organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $foreignStrategy = MarketingStrategy::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);
    $foreignCampaign = Campaign::factory()->create([
        'enterprise_id' => $foreignEnterprise,
        'marketing_strategy_id' => MarketingStrategy::factory()->create(['enterprise_id' => $foreignEnterprise]),
    ]);
    $series = ContentSeries::factory()->create(['campaign_id' => $campaign]);
    $foreignSeries = ContentSeries::factory()->create(['campaign_id' => $foreignCampaign]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise]);
    $foreignChannel = Channel::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $audience = Audience::factory()->create(['enterprise_id' => $enterprise]);
    $foreignAudience = Audience::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $item = ContentItem::factory()->forSeries($series)->forChannel($channel)->forAudience($audience)->create();
    $foreignItem = ContentItem::factory()->forSeries($foreignSeries)->forChannel($foreignChannel)->forAudience($foreignAudience)->create();

    $this->actingAs($user);

    expect(MarketingStrategyResource::getEloquentQuery()->pluck('id')->all())->toContain($strategy->id)->not->toContain($foreignStrategy->id)
        ->and(CampaignResource::getEloquentQuery()->pluck('id')->all())->toContain($campaign->id)->not->toContain($foreignCampaign->id)
        ->and(ContentSeriesResource::getEloquentQuery()->pluck('id')->all())->toContain($series->id)->not->toContain($foreignSeries->id)
        ->and(ContentItemResource::getEloquentQuery()->pluck('id')->all())->toContain($item->id)->not->toContain($foreignItem->id)
        ->and(ChannelResource::getEloquentQuery()->pluck('id')->all())->toContain($channel->id)->not->toContain($foreignChannel->id)
        ->and(AudienceResource::getEloquentQuery()->pluck('id')->all())->toContain($audience->id)->not->toContain($foreignAudience->id);
});

it('only allows enterprise managers to create marketing records', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    Enterprise::factory()->create(['organization_id' => $organization]);

    $this->actingAs($owner);
    expect(MarketingStrategyResource::canCreate())->toBeTrue()
        ->and(CampaignResource::canCreate())->toBeTrue()
        ->and(ContentSeriesResource::canCreate())->toBeTrue()
        ->and(ScriptResource::canCreate())->toBeTrue()
        ->and(ContentItemResource::canCreate())->toBeTrue()
        ->and(ChannelResource::canCreate())->toBeTrue()
        ->and(AudienceResource::canCreate())->toBeTrue();

    $this->actingAs($member);
    expect(MarketingStrategyResource::canCreate())->toBeFalse()
        ->and(CampaignResource::canCreate())->toBeFalse()
        ->and(ContentSeriesResource::canCreate())->toBeFalse()
        ->and(ScriptResource::canCreate())->toBeFalse()
        ->and(ContentItemResource::canCreate())->toBeFalse()
        ->and(ChannelResource::canCreate())->toBeFalse()
        ->and(AudienceResource::canCreate())->toBeFalse();
});