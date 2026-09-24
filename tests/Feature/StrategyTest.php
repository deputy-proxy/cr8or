<?php

use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Initiative;
use App\Models\Kpi;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('builds the strategy hierarchy from an enterprise objective to initiatives', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise]);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);

    $objective = Objective::factory()->forGoal($goal)->forKpi($kpi)->create([
        'enterprise_id' => $enterprise,
        'name' => 'Reach sustainable growth',
    ]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);
    $plan = Plan::factory()->create(['strategy_id' => $strategy]);
    $initiative = Initiative::factory()->create(['plan_id' => $plan]);

    expect($objective->enterprise->is($enterprise))->toBeTrue()
        ->and($objective->goal->is($goal))->toBeTrue()
        ->and($objective->kpi->is($kpi))->toBeTrue()
        ->and($objective->strategies->contains($strategy))->toBeTrue()
        ->and($strategy->objective->is($objective))->toBeTrue()
        ->and($strategy->plans->contains($plan))->toBeTrue()
        ->and($plan->strategy->is($strategy))->toBeTrue()
        ->and($plan->initiatives->contains($initiative))->toBeTrue()
        ->and($initiative->plan->is($plan))->toBeTrue();
});

it('references existing goals and KPIs without duplicating their state', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise, 'status' => 'active']);
    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise,
        'current_value' => 75,
        'target_value' => 100,
    ]);

    $objective = Objective::factory()->forGoal($goal)->forKpi($kpi)->create(['enterprise_id' => $enterprise]);

    $goal->update(['status' => 'archived']);
    $kpi->update(['current_value' => 80]);

    expect($objective->refresh()->goal->status)->toBe('archived')
        ->and($objective->kpi->current_value)->toBe('80.0000');
});

it('preserves ownership when strategic records are updated', function () {
    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);
    $plan = Plan::factory()->create(['strategy_id' => $strategy]);
    $initiative = Initiative::factory()->create(['plan_id' => $plan]);

    $objective->update(['name' => 'Updated objective']);
    $strategy->update(['name' => 'Updated strategy']);
    $plan->update(['name' => 'Updated plan']);
    $initiative->update(['name' => 'Updated initiative']);

    expect($objective->refresh()->enterprise->is($enterprise))->toBeTrue()
        ->and($strategy->refresh()->objective->is($objective))->toBeTrue()
        ->and($plan->refresh()->strategy->is($strategy))->toBeTrue()
        ->and($initiative->refresh()->plan->is($plan))->toBeTrue();
});

it('enforces the enterprise organization boundary through each strategic policy', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $objective = Objective::factory()->create(['enterprise_id' => $enterprise]);
    $foreignObjective = Objective::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective]);
    $foreignStrategy = Strategy::factory()->create(['objective_id' => $foreignObjective]);
    $plan = Plan::factory()->create(['strategy_id' => $strategy]);
    $foreignPlan = Plan::factory()->create(['strategy_id' => $foreignStrategy]);
    $initiative = Initiative::factory()->create(['plan_id' => $plan]);
    $foreignInitiative = Initiative::factory()->create(['plan_id' => $foreignPlan]);

    expect(Gate::forUser($owner)->allows('view', $objective))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $strategy))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $plan))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $initiative))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $foreignObjective))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignStrategy))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignPlan))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignInitiative))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Objective::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Objective::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForObjective', [Strategy::class, $objective]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForObjective', [Strategy::class, $objective]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForStrategy', [Plan::class, $strategy]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForStrategy', [Plan::class, $strategy]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForPlan', [Initiative::class, $plan]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForPlan', [Initiative::class, $plan]))->toBeFalse();
});

it('keeps the strategy domain schema explicit and non-polymorphic', function () {
    expect(Schema::getColumnListing('objectives'))->toBe([
        'id', 'enterprise_id', 'goal_id', 'kpi_id', 'name', 'description', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('strategies'))->toBe([
        'id', 'objective_id', 'name', 'description', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('plans'))->toBe([
        'id', 'strategy_id', 'name', 'description', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('initiatives'))->toBe([
        'id', 'plan_id', 'name', 'description', 'created_at', 'updated_at',
    ]);
});
