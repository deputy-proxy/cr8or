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
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

it('assembles the complete authorized strategic hierarchy for an Agent', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $goal = Goal::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Increase recurring revenue',
    ]);

    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Monthly recurring revenue',
    ]);

    $objective = Objective::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'goal_id' => $goal->getKey(),
        'kpi_id' => $kpi->getKey(),
        'name' => 'Improve retention',
    ]);

    $strategy = Strategy::factory()->create([
        'objective_id' => $objective->getKey(),
        'name' => 'Customer retention strategy',
    ]);

    $plan = Plan::factory()->create([
        'strategy_id' => $strategy->getKey(),
        'name' => 'Retention plan',
    ]);

    $initiative = Initiative::factory()->create([
        'plan_id' => $plan->getKey(),
        'name' => 'Customer success outreach',
    ]);

    $data = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['strategy'],
    )->section('strategy')?->data;

    expect($data)->toMatchArray([
        'enterprise' => [
            'id' => $enterprise->getKey(),
            'name' => $enterprise->name,
            'slug' => $enterprise->slug,
            'status' => $enterprise->status,
        ],
    ])
        ->and($data['goals'])->toHaveCount(1)
        ->and($data['goals'][0])->toMatchArray([
            'id' => $goal->getKey(),
            'name' => 'Increase recurring revenue',
            'description' => $goal->description,
            'status' => $goal->status,
        ])
        ->and($data['kpis'])->toHaveCount(1)
        ->and($data['kpis'][0])->toMatchArray([
            'id' => $kpi->getKey(),
            'name' => 'Monthly recurring revenue',
            'definition' => $kpi->definition,
            'unit' => $kpi->unit,
            'target_value' => $kpi->target_value,
            'current_value' => $kpi->current_value,
            'status' => $kpi->status,
        ])
        ->and($data['objectives'])->toHaveCount(1)
        ->and($data['objectives'][0])->toMatchArray([
            'id' => $objective->getKey(),
            'name' => 'Improve retention',
            'description' => $objective->description,
            'goal' => [
                'id' => $goal->getKey(),
                'name' => $goal->name,
                'description' => $goal->description,
                'status' => $goal->status,
            ],
            'kpi' => [
                'id' => $kpi->getKey(),
                'name' => $kpi->name,
                'definition' => $kpi->definition,
                'unit' => $kpi->unit,
                'target_value' => $kpi->target_value,
                'current_value' => $kpi->current_value,
                'status' => $kpi->status,
            ],
        ])
        ->and($data['objectives'][0]['strategies'][0])->toMatchArray([
            'id' => $strategy->getKey(),
            'name' => 'Customer retention strategy',
            'description' => $strategy->description,
        ])
        ->and($data['objectives'][0]['strategies'][0]['plans'][0])->toMatchArray([
            'id' => $plan->getKey(),
            'name' => 'Retention plan',
            'description' => $plan->description,
        ])
        ->and($data['objectives'][0]['strategies'][0]['plans'][0]['initiatives'][0])->toMatchArray([
            'id' => $initiative->getKey(),
            'name' => 'Customer success outreach',
            'description' => $initiative->description,
        ]);
});

it('preserves missing strategic relationships without failing context assembly', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $objective = Objective::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'goal_id' => null,
        'kpi_id' => null,
    ]);

    $strategy = Strategy::factory()->create([
        'objective_id' => $objective->getKey(),
    ]);

    $data = app(McpContextAssembler::class)->strategy($user, $enterprise->getKey());

    expect($data['goals'])->toBe([])
        ->and($data['kpis'])->toBe([])
        ->and($data['objectives'])->toHaveCount(1)
        ->and($data['objectives'][0]['goal'])->toBeNull()
        ->and($data['objectives'][0]['kpi'])->toBeNull()
        ->and($data['objectives'][0]['strategies'][0]['id'])->toBe($strategy->getKey())
        ->and($data['objectives'][0]['strategies'][0]['plans'])->toBe([]);
});

it('excludes strategic records from another Enterprise even within the same organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);
    $otherEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $includedGoal = Goal::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $excludedGoal = Goal::factory()->create(['enterprise_id' => $otherEnterprise->getKey()]);

    $includedKpi = Kpi::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $excludedKpi = Kpi::factory()->create(['enterprise_id' => $otherEnterprise->getKey()]);

    $includedObjective = Objective::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'goal_id' => $includedGoal->getKey(),
        'kpi_id' => $includedKpi->getKey(),
    ]);
    $excludedObjective = Objective::factory()->create([
        'enterprise_id' => $otherEnterprise->getKey(),
        'goal_id' => $excludedGoal->getKey(),
        'kpi_id' => $excludedKpi->getKey(),
    ]);

    $includedStrategy = Strategy::factory()->create(['objective_id' => $includedObjective->getKey()]);
    Strategy::factory()->create(['objective_id' => $excludedObjective->getKey()]);

    $data = app(McpContextAssembler::class)->strategy($user, $enterprise->getKey());

    expect($data['goals'])->toHaveCount(1)
        ->and($data['goals'][0]['id'])->toBe($includedGoal->getKey())
        ->and($data['kpis'])->toHaveCount(1)
        ->and($data['kpis'][0]['id'])->toBe($includedKpi->getKey())
        ->and($data['objectives'])->toHaveCount(1)
        ->and($data['objectives'][0]['id'])->toBe($includedObjective->getKey())
        ->and($data['objectives'][0]['strategies'][0]['id'])->toBe($includedStrategy->getKey())
        ->and(array_column($data['objectives'], 'id'))->not->toContain($excludedObjective->getKey())
        ->and(array_column($data['goals'], 'id'))->not->toContain($excludedGoal->getKey())
        ->and(array_column($data['kpis'], 'id'))->not->toContain($excludedKpi->getKey());
});

it('denies strategic context for an Enterprise outside the user organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create([
        'organization_id' => $foreignOrganization->getKey(),
    ]);

    expect(fn () => app(McpContextAssembler::class)->strategy($user, $foreignEnterprise->getKey()))
        ->toThrow(AuthorizationException::class);
});

it('bounds each strategic context collection', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    Goal::factory()->count(101)->create(['enterprise_id' => $enterprise->getKey()]);
    Kpi::factory()->count(101)->create(['enterprise_id' => $enterprise->getKey()]);
    Objective::factory()->count(101)->create(['enterprise_id' => $enterprise->getKey()]);

    $data = app(McpContextAssembler::class)->strategy($user, $enterprise->getKey());

    expect($data['goals'])->toHaveCount(100)
        ->and($data['kpis'])->toHaveCount(100)
        ->and($data['objectives'])->toHaveCount(100);
});
