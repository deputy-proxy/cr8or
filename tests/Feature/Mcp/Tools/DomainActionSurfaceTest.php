<?php

use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\DomainResourceService;
use LogicException;

it('creates an objective through the domain service with enterprise ownership intact', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $objective = app(DomainResourceService::class)->createObjective($user, $enterprise, [
        'name' => 'Growth objective',
        'description' => 'Created by the governed domain service.',
    ]);

    expect($objective->enterprise_id)->toBe($enterprise->id)
        ->and($objective->name)->toBe('Growth objective');
});

it('rejects a campaign when its marketing strategy belongs to another enterprise', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $foreignEnterprise->id]);

    expect(fn () => app(DomainResourceService::class)->createCampaign($user, $enterprise, [
        'marketing_strategy_id' => $strategy->id,
        'name' => 'Invalid campaign',
    ]))->toThrow(LogicException::class);

    expect(Campaign::query()->where('name', 'Invalid campaign')->exists())->toBeFalse();
});

it('enforces campaign lifecycle transitions through the domain service', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise->id,
        'marketing_strategy_id' => $strategy->id,
        'status' => Campaign::STATUS_DRAFT,
    ]);

    app(DomainResourceService::class)->transitionCampaign($user, $campaign, Campaign::STATUS_ACTIVE);

    expect($campaign->refresh()->status)->toBe(Campaign::STATUS_ACTIVE);
});
