<?php

namespace App\Services\Dashboard;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Issues\IssueResource;
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
        'objectives' => ['label' => 'Objectives', 'resource' => ObjectiveResource::class, 'created' => 'created_at', 'closed' => 'closed_at', 'status' => 'status'],
        'tasks' => ['label' => 'Tasks', 'resource' => TaskResource::class, 'created' => 'created_at', 'closed' => 'closed_at', 'status' => 'status'],
        'issues' => ['label' => 'Issues', 'resource' => IssueResource::class, 'created' => 'github_created_at', 'closed' => 'github_closed_at', 'status' => 'state'],
    ];

    /**
     * @return array{
     *   available: bool,
     *   entities: list<array{
     *     key: string,
     *     label: string,
     *     days: list<array{date: string, label: string, metrics: array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null}, reconciles: bool|null}>,
     *     summary: array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null},
     *     chart: array{width: int, height: int, baseline: int, ticks: list<array{value: int, y: float}>, days: list<array{date: string, label: string, bars: list<array{key: string, x: float, y: float, width: int, height: float, value: int, color: string, label_y: float}>, connectors: list<array{x1: float, x2: float, y: float}>, reconciles: bool|null}>}
     *   }>,
     *   limitations: list<string>,
     *   rangeLabel: string
     * }
     */
    public function forEnterprise(Enterprise $enterprise, string $range = '14_days'): array
    {
        $timezone = (string) config('app.timezone');
        $now = Carbon::now($timezone);
        $range = $range === 'today' ? 'today' : '14_days';
        $firstDay = $now->copy()->startOfDay()->subDays($range === 'today' ? 0 : 13);
        $dayStarts = [];

        for ($offset = 0; $offset < ($range === 'today' ? 1 : 14); $offset++) {
            $dayStarts[] = $firstDay->copy()->addDays($offset);
        }

        $entities = [];
        $limitations = [];

        foreach (self::ENTITIES as $key => $definition) {
            $resource = $definition['resource'];
            if (! $resource::canViewAny()) {
                continue;
            }

            $query = $resource::getEloquentQuery()->where('enterprise_id', $enterprise->id);
            $result = $this->aggregateEntity($query, $definition, $dayStarts, $now, (int) $enterprise->id);
            array_push($limitations, ...$result['limitations']);

            $days = [];
            foreach ($dayStarts as $index => $dayStart) {
                $metrics = $result['days'][$index];
                $complete = $metrics['open_start'] !== null
                    && $metrics['open_end'] !== null
                    && $metrics['created'] !== null
                    && $metrics['closed'] !== null;

                $days[] = [
                    'date' => $dayStart->toDateString(),
                    'label' => $dayStart->translatedFormat('j M'),
                    'metrics' => $metrics,
                    'reconciles' => $complete
                        ? $metrics['open_end'] === $metrics['open_start'] + $metrics['created'] - $metrics['closed']
                        : null,
                ];
            }

            $completedDays = array_values(array_filter($days, fn (array $day): bool => $day['metrics']['open_end'] !== null));
            $lastCompleted = $completedDays === [] ? null : $completedDays[array_key_last($completedDays)];
            $summary = [
                'open_start' => $days[0]['metrics']['open_start'] ?? null,
                'open_end' => $lastCompleted['metrics']['open_end'] ?? null,
                'created' => $this->sumCompleteMetric($days, 'created'),
                'closed' => $this->sumCompleteMetric($days, 'closed'),
            ];

            $entities[] = [
                'key' => $key,
                'label' => $definition['label'],
                'days' => $days,
                'summary' => $summary,
                'chart' => $this->buildWaterfallGeometry($days),
            ];
        }

        return [
            'available' => $entities !== [],
            'entities' => $entities,
            'limitations' => array_values(array_unique($limitations)),
            'rangeLabel' => $range === 'today' ? 'Today' : 'Last 14 days',
        ];
    }

    /**
     * @param  Builder<Model>  $query
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
            $selects[] = "SUM(CASE WHEN {$createdColumn} < ? AND ({$closedColumn} IS NULL OR {$closedColumn} >= ?) THEN 1 ELSE 0 END) AS open_start_{$index}";
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
        $unknownCloseCount = (clone $query)
            ->whereIn($entity['status'], $entity['status'] === 'state' ? ['closed'] : LifecycleStatus::closedStatuses())
            ->whereNull($closedColumn)
            ->count();
        $unknownCreationCount = $createdColumn === 'github_created_at'
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
     * @param  list<array{date: string, label: string, metrics: array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null}, reconciles: bool|null}>  $days
     */
    private function sumCompleteMetric(array $days, string $metric): ?int
    {
        $values = array_map(fn (array $day): ?int => $day['metrics'][$metric], $days);
        if (in_array(null, $values, true)) {
            return null;
        }

        return array_sum($values);
    }

    /**
     * @param  list<array{date: string, label: string, metrics: array{open_start: int|null, open_end: int|null, created: int|null, closed: int|null}, reconciles: bool|null}>  $days
     * @return array{width: int, height: int, baseline: int, ticks: list<array{value: int, y: float}>, days: list<array{date: string, label: string, bars: list<array{key: string, x: float, y: float, width: int, height: float, value: int, color: string, label_y: float}>, connectors: list<array{x1: float, x2: float, y: float}>, reconciles: bool|null}>}
     */
    private function buildWaterfallGeometry(array $days): array
    {
        $groupWidth = 84;
        $width = max(900, count($days) * $groupWidth + 48);
        $height = 250;
        $baseline = 204;
        $plotHeight = 166;
        $maxValue = 1;

        foreach ($days as $day) {
            $metrics = $day['metrics'];
            if ($metrics['open_start'] !== null && $metrics['created'] !== null) {
                $maxValue = max($maxValue, $metrics['open_start'] + $metrics['created']);
            }
            if ($metrics['open_end'] !== null) {
                $maxValue = max($maxValue, $metrics['open_end']);
            }
        }

        $scale = $plotHeight / $maxValue;
        $tickValues = array_values(array_unique([0, (int) ceil($maxValue / 2), $maxValue]));
        $ticks = array_map(fn (int $value): array => ['value' => $value, 'y' => $baseline - ($value * $scale)], $tickValues);
        $chartDays = [];

        foreach ($days as $index => $day) {
            $metrics = $day['metrics'];
            $groupX = 32 + ($index * $groupWidth);
            $barWidth = 12;
            $positions = ['open_start' => $groupX + 4, 'created' => $groupX + 22, 'closed' => $groupX + 40, 'open_end' => $groupX + 58];
            $bars = [];
            $connectors = [];
            $opening = $metrics['open_start'];
            $created = $metrics['created'];
            $closed = $metrics['closed'];
            $closing = $metrics['open_end'];
            $openingLevel = $opening === null ? null : $baseline - ($opening * $scale);
            $afterCreated = $opening === null || $created === null ? null : $opening + $created;
            $afterCreatedLevel = $afterCreated === null ? null : $baseline - ($afterCreated * $scale);
            $afterClosed = $afterCreated === null || $closed === null ? null : $afterCreated - $closed;
            $afterClosedLevel = $afterClosed === null ? null : $baseline - ($afterClosed * $scale);

            if ($opening !== null) {
                $bars[] = ['key' => 'open_start', 'x' => $positions['open_start'], 'y' => $openingLevel, 'width' => $barWidth, 'height' => max(1, $opening * $scale), 'value' => $opening, 'color' => '#60a5fa', 'label_y' => max(13, $openingLevel - 6)];
            }
            if ($openingLevel !== null && $afterCreatedLevel !== null) {
                $connectors[] = ['x1' => $positions['open_start'] + $barWidth, 'x2' => $positions['created'], 'y' => $openingLevel];
                $bars[] = ['key' => 'created', 'x' => $positions['created'], 'y' => $afterCreatedLevel, 'width' => $barWidth, 'height' => max(1, $created * $scale), 'value' => $created, 'color' => '#22c55e', 'label_y' => max(13, $afterCreatedLevel - 6)];
            }
            if ($afterCreatedLevel !== null && $afterClosedLevel !== null) {
                $connectors[] = ['x1' => $positions['created'] + $barWidth, 'x2' => $positions['closed'], 'y' => $afterCreatedLevel];
                $bars[] = ['key' => 'closed', 'x' => $positions['closed'], 'y' => $afterCreatedLevel, 'width' => $barWidth, 'height' => max(1, $closed * $scale), 'value' => -$closed, 'color' => '#ef4444', 'label_y' => min($baseline + 15, $afterClosedLevel + 14)];
            }
            if ($afterClosedLevel !== null && $closing !== null) {
                $connectors[] = ['x1' => $positions['closed'] + $barWidth, 'x2' => $positions['open_end'], 'y' => $afterClosedLevel];
            }
            if ($closing !== null) {
                $bars[] = ['key' => 'open_end', 'x' => $positions['open_end'], 'y' => $baseline - ($closing * $scale), 'width' => $barWidth, 'height' => max(1, $closing * $scale), 'value' => $closing, 'color' => '#2563eb', 'label_y' => max(13, $baseline - ($closing * $scale) - 6)];
            }

            $chartDays[] = [
                'date' => $day['date'],
                'label' => $day['label'],
                'bars' => $bars,
                'connectors' => $connectors,
                'reconciles' => $day['reconciles'],
            ];
        }

        return ['width' => $width, 'height' => $height, 'baseline' => $baseline, 'ticks' => $ticks, 'days' => $chartDays];
    }
}
