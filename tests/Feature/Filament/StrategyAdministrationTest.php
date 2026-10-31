<?php

use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Filament\Resources\Strategies\StrategyResource;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Strategy;
use App\Models\User;

it('scopes strategy resources to the authenticated users organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $visibleEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $visibleObjective = Objective::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $hiddenObjective = Objective::factory()->create(['enterprise_id' => $hiddenEnterprise]);
    $visibleStrategy = Strategy::factory()->create(['objective_id' => $visibleObjective]);
    $hiddenStrategy = Strategy::factory()->create(['objective_id' => $hiddenObjective]);
    $visibleInitiative = Initiative::factory()->create(['strategy_id' => $visibleStrategy]);
    $hiddenInitiative = Initiative::factory()->create(['strategy_id' => $hiddenStrategy]);

    $this->actingAs($user);

    expect(ObjectiveResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleObjective->id)->not->toContain($hiddenObjective->id)
        ->and(StrategyResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleStrategy->id)->not->toContain($hiddenStrategy->id)
        ->and(InitiativeResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleInitiative->id)->not->toContain($hiddenInitiative->id);
});

it('only allows enterprise managers to create strategy records in Filament', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);

    $this->actingAs($owner);
    expect(ObjectiveResource::canCreate())->toBeTrue()
        ->and(StrategyResource::canCreate())->toBeTrue()
        ->and(InitiativeResource::canCreate())->toBeTrue();

    $this->actingAs($member);
    expect(ObjectiveResource::canCreate())->toBeFalse()
        ->and(StrategyResource::canCreate())->toBeFalse()
        ->and(InitiativeResource::canCreate())->toBeFalse();

});
