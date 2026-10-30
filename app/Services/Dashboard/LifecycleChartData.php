<?php

namespace App\Services\Dashboard;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Issues\IssueResource;
use App\Filament\Resources\Milestones\MilestoneResource;
use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Enterprise;
use App\Support\LifecycleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class LifecycleChartData
{
    /** @var array<string, array{label: string, resource: class-string, created: string, closed: string, status: string}> */
    private const ENTITIES = [
        'goals' => ['label' => 'Goals', 'resource' => GoalResource::class, 'created' => 'created_at', 'closed' => 'closed_at', 'status' => 'status'],
        'objectives' => ['label' => 'Objectives', 'resource' => ObjectiveResource::class, 'created' => 'created_at', 'closed' => 'closed_at', 'status' => 'status'],
        'tasks' => ['label' => 'Tasks', 'resource' => TaskResource::class, 'created' => 'created_at', 'closed' => 'closed_at', 'status' => 'status'],
        'milestones' => ['label' => 'Milestones', 'resource' => MilestoneResource::class, 'created' => 'created_at', 'closed' => 'closed_at', 'status' => 'status'],
        'issues' => ['label' => 'GitHub Issues', 'resource' => IssueResource::class, 'created' => 'github_created_at', 'closed' => 'github_closed_at', 'status' => 'state'],
    ];

    /**
     * @return array{
     *   available: bool,
     *   selectedType: string,
     *   entityOptions: array<string, string>,
     *   days: list<array{date: string, label: string, metrics: array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null}}>,
     *   series: list<array{key: string, label: string, color: string, values: list<int|null>, path: string, points: list<array{x: float, y: float, value: int, index: int}>}>,
     *   limitations: list<string>,
     *   rangeLabel: string
     * }
     */
    public function forEnterprise(Enterprise $enterprise, string $range = '14_days', string $selectedType = 'all'): array
    {
        $timezone = (string) config('app.timezone');
        $now = Carbon::now($timezone);
        $range = $range === 'today' ? 'today' : '14_days';
        $firstDay = $now->copy()->startOfDay()->subDays($range === 'today' ? 0 : 13);
        $dayStarts = [];

        for ($offset = 0; $offset < ($range === 'today' ? 1 : 14); $offset++) {
            $dayStarts[] = $firstDay->copy()->addDays($offset);
        }

        $entityOptions = ['all' => 'All work'];
        foreach (self::ENTITIES as $key => $entity) {
            $resource = $entity['resource'];
            if ($resource::canViewAny()) {
                $entityOptions[$key] = $entity['label'];
            }
        }

        if (! array_key_exists($selectedType, $entityOptions)) {
            $selectedType = 'all';
        }

        $visibleTypes = $selectedType === 'all'
            ? array_intersect(array_keys(self::ENTITIES), array_keys($entityOptions))
            : [$selectedType];
        /** @var array<string, array{days: list<array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null}>, limitations: list<string>}> $entityMetrics */
        $entityMetrics = [];
        $limitations = [];

        foreach ($visibleTypes as $key) {
            $entity = self::ENTITIES[$key];
            $resource = $entity['resource'];
            $query = $resource::getEloquentQuery()->where('enterprise_id', $enterprise->id);
            $entityMetrics[$key] = $this->aggregateEntity($query, $entity, $dayStarts, $now, (int) $enterprise->id);
        }

        foreach ($visibleTypes as $type) {
            foreach ($entityMetrics[$type]['limitations'] as $limitation) {
                $limitations[$limitation] = $limitation;
            }
        }
        if ($selectedType === 'all' && count($entityMetrics) < count(self::ENTITIES)) {
            $limitations[] = 'Some entity types are not included because your account cannot view them.';
        }

        $days = [];
        foreach ($dayStarts as $index => $dayStart) {
            $values = ['open_start' => 0, 'open_end' => 0, 'created' => 0, 'closed' => 0];
            $metricComplete = ['open_start' => true, 'open_end' => true, 'created' => true, 'closed' => true];

            foreach ($visibleTypes as $type) {
                foreach ($values as $metric => $value) {
                    $entityValue = data_get($entityMetrics, [$type, 'days', $index, $metric]);
                    if (! is_int($entityValue)) {
                        $metricComplete[$metric] = false;
                    } else {
                        $values[$metric] += $entityValue;
                    }
                }
            }

            foreach ($values as $metric => $value) {
                if ($visibleTypes === [] || ! $metricComplete[$metric]) {
                    $values[$metric] = null;
                }
            }

            $days[] = [
                'date' => $dayStart->toDateString(),
                'label' => $dayStart->translatedFormat('j M'),
                'metrics' => $values,
            ];
        }

        $series = [];
        foreach ([
            'open_start' => ['label' => 'Open at start', 'color' => '#a1a1aa'],
            'open_end' => ['label' => 'Open at end', 'color' => '#60a5fa'],
            'created' => ['label' => 'Created', 'color' => '#34d399'],
            'closed' => ['label' => 'Closed', 'color' => '#f59e0b'],
        ] as $key => $definition) {
            $series[] = [
                'key' => $key,
                'label' => $definition['label'],
                'color' => $definition['color'],
                'values' => array_map(fn (array $day): ?int => $day['metrics'][$key], $days),
            ];
        }

        $series = $this->addChartGeometry($series, count($days));

        return [
            'available' => $visibleTypes !== [],
            'selectedType' => $selectedType,
            'entityOptions' => $entityOptions,
            'days' => $days,
            'series' => $series,
            'limitations' => array_values($limitations),
            'rangeLabel' => $range === 'today' ? 'Today' : 'Last 14 days',
        ];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array{label: string, resource: class-string, created: string, closed: string, status: string}  $entity
     * @param  list<Carbon>  $dayStarts
     * @return array{days: list<array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null}>, limitations: list<string>}
     */
    private function aggregateEntity(Builder $query, array $entity, array $dayStarts, Carbon $now, int $enterpriseId): array
    {
        $createdColumn = $entity['created'];
        $closedColumn = $entity['closed'];
        $selects = [];
        $bindings = [];

        foreach ($dayStarts as $index => $dayStart) {
            $start = $dayStart->copy()->utc()->format('Y-m-d H:i:s');
            $endBoundary = $dayStart->copy()->addDay()->utc()->format('Y-m-d H:i:s');
            $end = ($dayStart->isSameDay($now) ? $now->copy() : $dayStart->copy()->addDay())->utc()->format('Y-m-d H:i:s');
            $selects[] = "SUM(CASE WHEN {$createdColumn} < ? AND ({$closedColumn} IS NULL OR {$closedColumn} > ?) THEN 1 ELSE 0 END) AS open_start_{$index}";
            array_push($bindings, $start, $start);
            $selects[] = "SUM(CASE WHEN {$createdColumn} < ? AND ({$closedColumn} IS NULL OR {$closedColumn} >= ?) THEN 1 ELSE 0 END) AS open_end_{$index}";
            array_push($bindings, $endBoundary, $endBoundary);
            $partialDayOperator = $dayStart->isSameDay($now) ? '<=' : '<';
            $selects[] = "SUM(CASE WHEN {$createdColumn} >= ? AND {$createdColumn} {$partialDayOperator} ? THEN 1 ELSE 0 END) AS created_{$index}";
            array_push($bindings, $start, $end);
            $selects[] = "SUM(CASE WHEN {$closedColumn} >= ? AND {$closedColumn} {$partialDayOperator} ? THEN 1 ELSE 0 END) AS closed_{$index}";
            array_push($bindings, $start, $end);
        }

        /** @var literal-string $expression */
        $expression = implode(', ', $selects);
        $aggregate = (clone $query)->selectRaw($expression, $bindings)->first();
        $unknownCloseQuery = (clone $query)
            ->whereIn($entity['status'], $entity['status'] === 'state' ? ['closed'] : LifecycleStatus::closedStatuses())
            ->whereNull($closedColumn);
        $unknownCloseCount = $unknownCloseQuery->count();
        $unknownCreationCount = $entity['created'] === 'github_created_at'
            ? (clone $query)->whereNull($createdColumn)->count()
            : 0;

        /** @var class-string<Model> $modelClass */
        $modelClass = $entity['resource']::getModel();
        $model = new $modelClass;
        $canInspectEvents = EventResource::canViewAny();
        $hasReopenEvents = $canInspectEvents
            ? EventResource::getEloquentQuery()
                ->where('enterprise_id', $enterpriseId)
                ->where('event_type', 'open')
                ->where('subject_type', $model->getMorphClass())
                ->exists()
            : true;

        $limitations = [];
        if ($unknownCloseCount > 0) {
            $limitations[] = $entity['label'].': '.$unknownCloseCount.' currently closed record(s) have no reliable close timestamp; open/closed history is incomplete.';
        }
        if ($unknownCreationCount > 0) {
            $limitations[] = $entity['label'].': '.$unknownCreationCount.' record(s) have no provider creation timestamp; opening history is incomplete.';
        }
        if ($hasReopenEvents) {
            $limitations[] = $canInspectEvents
                ? $entity['label'].': at least one record was reopened; the single close timestamp cannot reconstruct its earlier lifecycle intervals.'
                : $entity['label'].': reopening history cannot be validated with your current event permissions.';
        }

        $openHistoryComplete = $unknownCloseCount === 0 && ! $hasReopenEvents && $unknownCreationCount === 0;
        $closedHistoryComplete = $unknownCloseCount === 0 && ! $hasReopenEvents;
        $creationHistoryComplete = $unknownCreationCount === 0;
        $days = [];

        foreach ($dayStarts as $index => $dayStart) {
            $isCurrentDay = $dayStart->isSameDay($now);
            $days[] = [
                'open_start' => $openHistoryComplete ? (int) ($aggregate->getAttribute('open_start_'.$index) ?? 0) : null,
                'open_end' => $isCurrentDay || ! $openHistoryComplete ? null : (int) ($aggregate->getAttribute('open_end_'.$index) ?? 0),
                'created' => $creationHistoryComplete ? (int) ($aggregate->getAttribute('created_'.$index) ?? 0) : null,
                'closed' => $closedHistoryComplete ? (int) ($aggregate->getAttribute('closed_'.$index) ?? 0) : null,
            ];
        }

        return ['days' => $days, 'limitations' => $limitations];
    }

    /**
     * @param  list<array{key: string, label: string, color: string, values: list<int|null>}>  $series
     * @return list<array{key: string, label: string, color: string, values: list<int|null>, path: string, points: list<array{x: float, y: float, value: int, index: int}>}>
     */
    private function addChartGeometry(array $series, int $dayCount): array
    {
        $allValues = array_merge(...array_map(fn (array $item): array => $item['values'], $series));
        $maxValue = max(array_merge([1], array_filter($allValues, fn (?int $value): bool => $value !== null)));
        $plotWidth = 820;
        $plotHeight = 170;
        $left = $dayCount === 1 ? 452 : 42;
        $top = 18;
        $step = $dayCount > 1 ? $plotWidth / ($dayCount - 1) : 0;

        foreach ($series as &$item) {
            $segments = [];
            $currentSegment = [];
            $points = [];
            foreach ($item['values'] as $index => $value) {
                if ($value === null) {
                    if ($currentSegment !== []) {
                        $segments[] = $currentSegment;
                        $currentSegment = [];
                    }

                    continue;
                }
                $point = [
                    'x' => $left + ($step * $index),
                    'y' => $top + $plotHeight - (($value / $maxValue) * $plotHeight),
                    'value' => $value,
                    'index' => $index,
                ];
                $currentSegment[] = $point;
                $points[] = $point;
            }
            if ($currentSegment !== []) {
                $segments[] = $currentSegment;
            }

            $item['path'] = implode(' ', array_map(
                fn (array $segment): string => 'M '.implode(' L ', array_map(fn (array $point): string => round($point['x'], 2).' '.round($point['y'], 2), $segment)),
                $segments,
            ));
            $item['points'] = $points;
        }
        unset($item);

        return $series;
    }
}
