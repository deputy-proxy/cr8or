<?php

namespace App\Services;

use App\Data\Reporting\PerformanceResult;
use App\Models\AgentExecution;
use App\Models\BusinessHealthResult;
use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\FinancialReport;
use App\Models\Kpi;
use App\Models\MetricDefinition;
use App\Models\Report;
use App\Models\ReportMetricValue;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class BusinessPerformanceReportingService
{
    public const REPORT_TYPE = 'business_performance';

    public const METHODOLOGY_VERSION = '1.0';

    public function generate(
        User $user,
        Enterprise $enterprise,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): PerformanceResult {
        Gate::forUser($user)->authorize('view', $enterprise);

        if ($periodEnd->lessThan($periodStart)) {
            throw new \InvalidArgumentException('Report period end must not precede period start.');
        }

        return DB::transaction(function () use ($enterprise, $periodStart, $periodEnd): PerformanceResult {
            $source = $this->collectSourceData($enterprise, $periodStart, $periodEnd);
            $definitions = $this->definitions($enterprise);

            $report = Report::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'report_type' => self::REPORT_TYPE,
                'status' => Report::STATUS_COMPLETED,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'generated_at' => Carbon::now(),
                'methodology_version' => self::METHODOLOGY_VERSION,
            ]);

            $snapshotPayload = [
                'enterprise_id' => $enterprise->getKey(),
                'period' => [
                    'start' => $periodStart->toISOString(),
                    'end' => $periodEnd->toISOString(),
                ],
                'methodology_version' => self::METHODOLOGY_VERSION,
                'sources' => $source,
            ];

            ReportSnapshot::query()->create([
                'report_id' => $report->getKey(),
                'captured_at' => Carbon::now(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'methodology_version' => self::METHODOLOGY_VERSION,
                'source_records' => $snapshotPayload,
                'source_fingerprint' => hash('sha256', json_encode($snapshotPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ]);

            $metricRows = $this->metricRows($source);

            foreach ($metricRows as $key => $metric) {
                $definition = $definitions[$key];

                ReportMetricValue::query()->create([
                    'report_id' => $report->getKey(),
                    'metric_definition_id' => $definition->getKey(),
                    'value' => $metric['value'],
                    'unit' => $metric['unit'],
                    'calculation' => $metric['calculation'],
                    'source_records' => $metric['source_records'],
                ]);
            }

            $report->load('snapshot', 'metricValues.metricDefinition');

            return new PerformanceResult(
                report: $report,
                metrics: $this->metricPayload($report),
                provenance: [
                    'report_type' => self::REPORT_TYPE,
                    'methodology_version' => self::METHODOLOGY_VERSION,
                    'snapshot_id' => $report->snapshot?->getKey(),
                    'source_fingerprint' => $report->snapshot?->source_fingerprint,
                ],
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );
        });
    }

    /** @return array<string, mixed> */
    public function latest(User $user, Enterprise $enterprise): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $report = Report::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->where('report_type', self::REPORT_TYPE)
            ->where('status', Report::STATUS_COMPLETED)
            ->with('snapshot', 'metricValues.metricDefinition')
            ->latest('generated_at')
            ->first();

        if ($report === null) {
            return [
                'report' => null,
                'metrics' => [],
                'provenance' => null,
            ];
        }

        return [
            'report' => [
                'id' => $report->getKey(),
                'type' => $report->report_type,
                'period_start' => Carbon::parse((string) $report->period_start)->toISOString(),
                'period_end' => Carbon::parse((string) $report->period_end)->toISOString(),
                'generated_at' => Carbon::parse((string) $report->generated_at)->toISOString(),
            ],
            'metrics' => $this->metricPayload($report),
            'provenance' => [
                'methodology_version' => $report->methodology_version,
                'snapshot_id' => $report->snapshot?->getKey(),
                'source_fingerprint' => $report->snapshot?->source_fingerprint,
            ],
        ];
    }

    /** @return array<string, MetricDefinition> */
    private function definitions(Enterprise $enterprise): array
    {
        $definitions = [
            'finance_net_movement' => [
                'name' => 'Finance net movement',
                'description' => 'Net movement from the latest authoritative financial report within the calculation period.',
                'unit' => 'currency',
                'methodology' => 'Reuse FinancialReport metrics without recalculating Finance domain state.',
            ],
            'marketing_active_campaigns' => [
                'name' => 'Active campaigns',
                'description' => 'Marketing campaigns active during the calculation period.',
                'unit' => 'count',
                'methodology' => 'Count campaigns whose creation timestamp falls inside the calculation period and whose status is active.',
            ],
            'strategy_objectives_created' => [
                'name' => 'Strategy objectives created',
                'description' => 'Strategy objectives created during the calculation period.',
                'unit' => 'count',
                'methodology' => 'Count enterprise objectives created inside the calculation period.',
            ],
            'work_items_created' => [
                'name' => 'Work items created',
                'description' => 'Work items created during the calculation period.',
                'unit' => 'count',
                'methodology' => 'Count enterprise work items created inside the calculation period.',
            ],
            'agent_executions_completed' => [
                'name' => 'Agent executions completed',
                'description' => 'Agent executions completed during the calculation period.',
                'unit' => 'count',
                'methodology' => 'Count enterprise Agent executions completed inside the calculation period.',
            ],
            'agent_executions_failed' => [
                'name' => 'Agent executions failed',
                'description' => 'Agent executions failed during the calculation period.',
                'unit' => 'count',
                'methodology' => 'Count enterprise Agent executions failed inside the calculation period.',
            ],
            'business_health_attention' => [
                'name' => 'Business health attention results',
                'description' => 'Business health results marked attention during the calculation period.',
                'unit' => 'count',
                'methodology' => 'Count authoritative BusinessHealthResult records evaluated inside the calculation period with attention status.',
            ],
            'kpis_active' => [
                'name' => 'Active KPIs',
                'description' => 'Current active enterprise KPI definitions available when the report is generated.',
                'unit' => 'count',
                'methodology' => 'Read active KPI definitions as current enterprise strategy state; this is explicitly not a historical KPI value.',
            ],
        ];

        $result = [];
        foreach ($definitions as $key => $data) {
            $result[$key] = MetricDefinition::query()->firstOrCreate(
                ['enterprise_id' => $enterprise->getKey(), 'key' => $key],
                $data + ['status' => MetricDefinition::STATUS_ACTIVE],
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function collectSourceData(Enterprise $enterprise, Carbon $periodStart, Carbon $periodEnd): array
    {
        $campaigns = Campaign::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderBy('id')
            ->get(['id', 'status', 'created_at']);

        $objectives = $enterprise->objectives()
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderBy('id')
            ->get(['id', 'name', 'created_at']);

        $workItems = WorkItem::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderBy('id')
            ->get(['id', 'status', 'created_at']);

        $executions = AgentExecution::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereBetween('requested_at', [$periodStart, $periodEnd])
            ->orderBy('id')
            ->get(['id', 'status', 'requested_at', 'completed_at']);

        $health = BusinessHealthResult::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereBetween('evaluated_at', [$periodStart, $periodEnd])
            ->orderBy('id')
            ->get(['id', 'health_status', 'evaluated_at']);

        $financialReports = FinancialReport::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereBetween('generated_at', [$periodStart, $periodEnd])
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get(['id', 'financial_period_id', 'metrics', 'generated_at']);

        return [
            'finance' => $financialReports->map(fn (FinancialReport $report): array => [
                'id' => $report->getKey(),
                'financial_period_id' => $report->financial_period_id,
                'metrics' => $report->metrics,
                'generated_at' => Carbon::parse((string) $report->generated_at)->toISOString(),
            ])->all(),
            'marketing' => $campaigns->map(fn (Campaign $campaign): array => [
                'id' => $campaign->getKey(),
                'status' => $campaign->status,
                'created_at' => Carbon::parse((string) $campaign->created_at)->toISOString(),
            ])->all(),
            'strategy' => $objectives->map(fn ($objective): array => [
                'id' => $objective->getKey(),
                'name' => $objective->name,
                'created_at' => Carbon::parse((string) $objective->created_at)->toISOString(),
            ])->all(),
            'work' => $workItems->map(fn (WorkItem $workItem): array => [
                'id' => $workItem->getKey(),
                'status' => $workItem->status,
                'created_at' => Carbon::parse((string) $workItem->created_at)->toISOString(),
            ])->all(),
            'agent_execution' => $executions->map(fn (AgentExecution $execution): array => [
                'id' => $execution->getKey(),
                'status' => $execution->status,
                'requested_at' => Carbon::parse((string) $execution->requested_at)->toISOString(),
                'completed_at' => $execution->completed_at === null ? null : Carbon::parse((string) $execution->completed_at)->toISOString(),
            ])->all(),
            'business_health' => $health->map(fn (BusinessHealthResult $result): array => [
                'id' => $result->getKey(),
                'health_status' => $result->health_status,
                'evaluated_at' => Carbon::parse((string) $result->evaluated_at)->toISOString(),
            ])->all(),
            'kpis' => Kpi::query()
                ->where('enterprise_id', $enterprise->getKey())
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['id', 'name', 'unit', 'current_value'])
                ->map(fn (Kpi $kpi): array => [
                    'id' => $kpi->getKey(),
                    'name' => $kpi->name,
                    'unit' => $kpi->unit,
                    'current_value' => $kpi->current_value,
                ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, array{value: string, unit: string, calculation: string, source_records: array<int, mixed>}>
     */
    private function metricRows(array $source): array
    {
        $finance = $source['finance'];
        $latestFinance = $finance === [] ? null : $finance[0];
        $financeNet = $latestFinance === null ? '0.0000' : (string) ($latestFinance['metrics']['net_movement'] ?? '0.0000');

        return [
            'finance_net_movement' => ['value' => $financeNet, 'unit' => 'currency', 'calculation' => 'Copied from the latest FinancialReport.net_movement in the report period.', 'source_records' => $latestFinance === null ? [] : [$latestFinance]],
            'marketing_active_campaigns' => ['value' => (string) count(array_filter($source['marketing'], fn (array $row): bool => $row['status'] === Campaign::STATUS_ACTIVE)), 'unit' => 'count', 'calculation' => 'Count source campaign records with active status.', 'source_records' => $source['marketing']],
            'strategy_objectives_created' => ['value' => (string) count($source['strategy']), 'unit' => 'count', 'calculation' => 'Count source objective records created in the report period.', 'source_records' => $source['strategy']],
            'work_items_created' => ['value' => (string) count($source['work']), 'unit' => 'count', 'calculation' => 'Count source work-item records created in the report period.', 'source_records' => $source['work']],
            'agent_executions_completed' => ['value' => (string) count(array_filter($source['agent_execution'], fn (array $row): bool => $row['status'] === AgentExecution::STATUS_COMPLETED)), 'unit' => 'count', 'calculation' => 'Count source Agent execution records completed in the report period.', 'source_records' => $source['agent_execution']],
            'agent_executions_failed' => ['value' => (string) count(array_filter($source['agent_execution'], fn (array $row): bool => $row['status'] === AgentExecution::STATUS_FAILED)), 'unit' => 'count', 'calculation' => 'Count source Agent execution records failed in the report period.', 'source_records' => $source['agent_execution']],
            'business_health_attention' => ['value' => (string) count(array_filter($source['business_health'], fn (array $row): bool => $row['health_status'] === 'attention')), 'unit' => 'count', 'calculation' => 'Count source BusinessHealthResult records with attention status.', 'source_records' => $source['business_health']],
            'kpis_active' => ['value' => (string) count($source['kpis']), 'unit' => 'count', 'calculation' => 'Count active KPI definitions at report generation time; no historical KPI value is inferred.', 'source_records' => $source['kpis']],
        ];
    }

    /** @return array<string, array{value: string, unit: string|null, calculation: string}> */
    private function metricPayload(Report $report): array
    {
        return $report->metricValues->mapWithKeys(fn (ReportMetricValue $metric): array => [
            $metric->metricDefinition->key => [
                'value' => (string) $metric->value,
                'unit' => $metric->unit,
                'calculation' => $metric->calculation,
            ],
        ])->all();
    }
}