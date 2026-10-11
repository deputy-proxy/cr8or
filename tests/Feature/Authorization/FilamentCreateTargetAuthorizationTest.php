<?php

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('rejects a Filament create request targeting an enterprise outside the current users organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterpriseA = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $enterpriseB = Enterprise::factory()->create(['organization_id' => $organizationB->id]);
    $user = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organizationA->id]);

    $strategyB = MarketingStrategy::factory()->create(['enterprise_id' => $enterpriseB->id]);
    $campaignB = Campaign::factory()->create(['enterprise_id' => $enterpriseB->id, 'marketing_strategy_id' => $strategyB->id]);
    $contentItemB = ContentItem::factory()->forCampaign($campaignB)->create();

    $this->actingAs($user);

    Livewire::test(CreateAsset::class)
        ->fillForm([
            'enterprise_id' => $enterpriseB->id,
            'content_item_id' => $contentItemB->id,
            'name' => 'Foreign asset',
            'type' => 'image',
            'status' => Asset::STATUS_PENDING,
        ])
        ->call('create')
        ->assertForbidden();

    expect(Asset::query()->count())->toBe(0);
});

it('allows a Filament create request within the current users authorized enterprise', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->id, 'marketing_strategy_id' => $strategy->id]);
    $contentItem = ContentItem::factory()->forCampaign($campaign)->create();

    $this->actingAs($user);

    Livewire::test(CreateAsset::class)
        ->fillForm([
            'enterprise_id' => $enterprise->id,
            'content_item_id' => $contentItem->id,
            'name' => 'Authorized asset',
            'type' => 'image',
            'status' => Asset::STATUS_PENDING,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Asset::query()->where('name', 'Authorized asset')->exists())->toBeTrue();
});

it('rejects an edit request that attempts to move a record into another organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterpriseA = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $enterpriseB = Enterprise::factory()->create(['organization_id' => $organizationB->id]);
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organizationA->id]);

    $strategyA = MarketingStrategy::factory()->create(['enterprise_id' => $enterpriseA->id]);
    $campaignA = Campaign::factory()->create(['enterprise_id' => $enterpriseA->id, 'marketing_strategy_id' => $strategyA->id]);
    $contentItemA = ContentItem::factory()->forCampaign($campaignA)->create();
    $strategyB = MarketingStrategy::factory()->create(['enterprise_id' => $enterpriseB->id]);
    $campaignB = Campaign::factory()->create(['enterprise_id' => $enterpriseB->id, 'marketing_strategy_id' => $strategyB->id]);
    $contentItemB = ContentItem::factory()->forCampaign($campaignB)->create();
    $asset = Asset::factory()->create([
        'enterprise_id' => $enterpriseA->id,
        'content_item_id' => $contentItemA->id,
        'status' => Asset::STATUS_PENDING,
    ]);

    $this->actingAs($user);

    Livewire::test(EditAsset::class, ['record' => $asset->getKey()])
        ->fillForm([
            'enterprise_id' => $enterpriseB->id,
            'content_item_id' => $contentItemB->id,
            'name' => 'Moved asset',
            'type' => 'image',
            'status' => Asset::STATUS_PENDING,
        ])
        ->call('save')
        ->assertForbidden();

    expect($asset->fresh()->enterprise_id)->toBe($enterpriseA->id)
        ->and($asset->fresh()->content_item_id)->toBe($contentItemA->id);
});
