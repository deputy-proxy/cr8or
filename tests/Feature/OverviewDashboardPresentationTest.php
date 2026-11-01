<?php

use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Initiative;
use App\Models\Kpi;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Operations\RecordKpiMeasurement;
use App\Services\Dashboard\OverviewDashboardData;

it('builds enterprise-scoped strategy execution and KPI summary data', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $goal = Goal::factory()->create(['enterprise_id' => $enterprise->id, 'name' => 'Grow revenue']);
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->id, 'goal_id' => $goal->id]);
    $strategy = Strategy::factory()->create([
        'objective_id' => $objective->id,
        'name' => 'Improve retention',
        'description' => 'Build a stronger customer renewal process.',
    ]);

    Project::factory()->create(['enterprise_id' => $enterprise->id]);
    Initiative::factory()->create(['enterprise_id' => $enterprise->id, 'strategy_id' => $strategy->id]);
    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise->id,
        'name' => 'Renewal rate',
        'target_value' => 100,
    ]);
    app(RecordKpiMeasurement::class)->execute($user, [
        'kpi_id' => $kpi->getKey(),
        'value' => 75,
        'measured_at' => '2026-01-01',
    ]);
    Kpi::factory()->create(['enterprise_id' => $otherEnterprise->id]);

    $this->actingAs($user);
    $data = app(OverviewDashboardData::class)->forEnterprise($enterprise);

    expect($data['strategyOverview']['count'])->toBe(1)
        ->and($data['strategyOverview']['items'][0]['name'])->toBe('Improve retention')
        ->and($data['strategyOverview']['items'][0]['description'])->toBe('Build a stronger customer renewal process.')
        ->and($data['goalOverview']['count'])->toBe(1)
        ->and($data['goalOverview']['objective_count'])->toBe(1)
        ->and($data['executionOverview'])->toBe(['projects' => 1, 'initiatives' => 1])
        ->and($data['kpiOverview']['count'])->toBe(1)
        ->and($data['kpiOverview']['items'][0]['current'])->toBe(75.0)
        ->and($data['kpiOverview']['items'][0]['target'])->toBe(100.0);
});
