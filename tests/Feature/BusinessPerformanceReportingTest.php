<?php

use App\Models\AgentExecution;
use App\Models\BusinessHealthResult;
use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\FinancialReport;
use App\Models\MarketingStrategy;
use App\Models\MetricDefinition;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\BusinessPerformanceReportingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use LogicException;

function reportingWindow(): array
{
    return [Carbon::parse('2026-09-01T00:00:00Z'), Carbon::parse('2026-09-30T23:59:59Z')];
}

it('generates a cross-domain report without mutating authoritative source records', function () {
    [$start, $end] = reportingWindow();
    $organization = App\Models\Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    App\Models\Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $marketingStrategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    Campaign::factory()->create([
        'marketing_strategy_id' => $marketingStrategy->id,
        'status' => Campaign::STATUS_ACTIVE,
        'created_at' => Carbon::parse('2026-09-10T10:00:00Z'),
    ]);
    App\Models\Objective::factory()->create([
        'enterprise_id' => $enterprise->id,
        'created_at' => Carbon::parse('2026-09-11T10:00:00Z'),
    ]);
    App\Models\WorkItem::factory()->create([
        'enterprise_id' => $enterprise->id,
        'created_at' => Carbon::parse('2026-09-12T10:00:00Z'),
    ]);
    AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'status' => AgentExecution::STATUS_COMPLETED,
        'requested_at' => Carbon::parse('2026-09-13T10:00:00Z'),
    ]);
    AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'status' => AgentExecution::STATUS_FAILED,
        'failure_reason' => 'test failure',
        'requested_at' => Carbon::parse('2026-09-14T10:00:00Z'),
    ]);
    $financial = FinancialReport::factory()->create([
        'enterprise_id' => $enterprise->id,
        'metrics' => ['net_movement' => '1250.0000'],
        'generated_at' => Carbon::parse('2026-09-15T10:00:00Z'),
    ]);
    BusinessHealthResult::factory()->create([
        'enterprise_id' => $enterprise->id,
        'financial_report_id' => $financial->id,
        'health_status' => 'attention',
        'evaluated_at' => Carbon::parse('2026-09-16T10:00:00Z'),
    ]);

    $result = app(BusinessPerformanceReportingService::class)->generate($user, $enterprise, $start, $end);

    expect($result->report->report_type)->toBe(BusinessPerformanceReportingService::REPORT_TYPE)
        ->and($result->metrics['finance_net_movement']['value'])->toBe('1250.0000')
        ->and($result->metrics['marketing_active_campaigns']['value'])->toBe('1.0000')
        ->and($result->metrics['strategy_objectives_created']['value'])->toBe('1.0000')
        ->and($result->metrics['work_items_created']['value'])->toBe('1.0000')
        ->and($result->metrics['agent_executions_completed']['value'])->toBe('1.0000')
        ->and($result->metrics['agent_executions_failed']['value'])->toBe('1.0000')
        ->and($result->metrics['business_health_attention']['value'])->toBe('1.0000')
        ->and($result->provenance['source_fingerprint'])->toHaveLength(64)
        ->and(ReportSnapshot::query()->where('report_id', $result->report->id)->exists())->toBeTrue()
        ->and(Campaign::query()->where('enterprise_id', $enterprise->id)->count())->toBe(1)
        ->and(AgentExecution::query()->where('enterprise_id', $enterprise->id)->count())->toBe(2);
});

it('excludes foreign tenant data and preserves tenant authorization', function () {
    [$start, $end] = reportingWindow();
    $organization = App\Models\Organization::factory()->create();
    $foreignOrganization = App\Models\Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $user = User::factory()->create();
    App\Models\Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    Campaign::factory()->create(['enterprise_id' => $foreignEnterprise->id, 'status' => Campaign::STATUS_ACTIVE]);
    $result = app(BusinessPerformanceReportingService::class)->generate($user, $enterprise, $start, $end);

    expect($result->metrics['marketing_active_campaigns']['value'])->toBe('0.0000')
        ->and(fn () => Gate::forUser($user)->authorize('view', $foreignEnterprise))
        ->toThrow(Exception::class);
});

it('keeps snapshots and metric values immutable and calculations reproducible', function () {
    [$start, $end] = reportingWindow();
    $organization = App\Models\Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    App\Models\Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    Campaign::factory()->count(2)->create([
        'enterprise_id' => $enterprise->id,
        'status' => Campaign::STATUS_ACTIVE,
        'created_at' => Carbon::parse('2026-09-10T10:00:00Z'),
    ]);

    $first = app(BusinessPerformanceReportingService::class)->generate($user, $enterprise, $start, $end);
    $firstSnapshot = $first->report->snapshot;
    $firstMetric = $first->report->metricValues->first();

    expect(fn () => $firstSnapshot->update(['source_fingerprint' => str_repeat('0', 64)]))
        ->toThrow(LogicException::class, 'Report snapshots are immutable.');

    expect(fn () => $firstMetric->update(['value' => '999.0000']))
        ->toThrow(LogicException::class, 'Report metric values are immutable.');

    $second = app(BusinessPerformanceReportingService::class)->generate($user, $enterprise, $start, $end);

    expect($second->metrics['marketing_active_campaigns']['value'])
        ->toBe($first->metrics['marketing_active_campaigns']['value'])
        ->and($second->provenance['source_fingerprint'])
        ->toBe($first->provenance['source_fingerprint']);
});

it('does not pretend current KPI definitions are historical values', function () {
    [$start, $end] = reportingWindow();
    $organization = App\Models\Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    App\Models\Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    MetricDefinition::query()->create([
        'enterprise_id' => $enterprise->id,
        'key' => 'custom_metric',
        'name' => 'Custom metric',
        'description' => 'test',
        'unit' => 'count',
        'methodology' => 'test',
        'status' => MetricDefinition::STATUS_ACTIVE,
    ]);

    $result = app(BusinessPerformanceReportingService::class)->generate($user, $enterprise, $start, $end);

    expect($result->report->methodology_version)->toBe(BusinessPerformanceReportingService::METHODOLOGY_VERSION)
        ->and($result->provenance['methodology_version'])->toBe(BusinessPerformanceReportingService::METHODOLOGY_VERSION);
});