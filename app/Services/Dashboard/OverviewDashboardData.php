<?php

namespace App\Services\Dashboard;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Kpis\KpiResource;
use App\Filament\Resources\Milestones\MilestoneResource;
use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Strategies\StrategyResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Initiative;
use App\Models\Kpi;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Authorization-aware read model for the enterprise Overview page.
 * Queries reuse each Filament resource's authorized base query and then narrow it to one enterprise.
 */
class OverviewDashboardData
{
    /**
     * @return array{
     *   summaryCards: array<int, array{label: string, count: int|null, url: string|null}>,
     *   strategyOverview: array{count: int|null, items: array<int, array{name: string, description: string|null, url: string}>},
     *   goalOverview: array{count: int|null, objective_count: int|null, items: array<int, array{name: string, description: string|null, status: string}>},
     *   executionOverview: array{projects: int|null, initiatives: int|null},
     *   kpiOverview: array{count: int|null, items: array<int, array{name: string, definition: string, unit: string|null, current: float|null, target: float|null, url: string}>},
     *   listCards: array<int, array{label: string, url: string|null, items: array<int, array{title: string, status: string|null, due: string|null}>}>,
     *   activityPanels: array<int, array{label: string, url: string|null, items: array<int, array{title: string, status: string|null, due: string|null}>}>,
     *   activityAvailable: bool,
     *   activityRangeLabel: string,
     *   activityDays: array<int, array{date: string, events: array<int, array{description: string, type: string, actor: string, time: string}>}>,
     *   lifecycleChart: array<string, mixed>
     * }
     */
    public function forEnterprise(Enterprise $enterprise, string $activityRange = '14_days'): array
    {
        $enterpriseId = (int) $enterprise->id;
        /** @var Builder<Task> $tasks */
        $tasks = TaskResource::canViewAny()
            ? TaskResource::getEloquentQuery()->where('enterprise_id', $enterpriseId)
            : Task::query()->whereRaw('1 = 0');
        $actionableTasks = (clone $tasks)->whereNotIn('status', ['done', 'completed', 'cancelled', 'canceled']);
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);
        $startOfNextWeek = $startOfWeek->copy()->addWeek();
        $startOfFollowingWeek = $startOfNextWeek->copy()->addWeek();

        /** @var Builder<Project> $projects */
        $projects = ProjectResource::canViewAny() ? ProjectResource::getEloquentQuery()->where('enterprise_id', $enterpriseId) : Project::query()->whereRaw('1 = 0');
        /** @var Builder<Initiative> $initiatives */
        $initiatives = InitiativeResource::canViewAny() ? InitiativeResource::getEloquentQuery()->where('enterprise_id', $enterpriseId) : Initiative::query()->whereRaw('1 = 0');
        /** @var Builder<Milestone> $milestones */
        $milestones = MilestoneResource::canViewAny() ? MilestoneResource::getEloquentQuery()->where('enterprise_id', $enterpriseId) : Milestone::query()->whereRaw('1 = 0');

        $listCards = [
            ['label' => 'Projects', 'url' => ProjectResource::canViewAny() ? ProjectResource::getUrl() : null, 'items' => (clone $projects)->where('status', 'active')->orderByDesc('updated_at')->limit(5)->get()->map(fn (Project $r): array => ['title' => $r->name, 'status' => $r->status, 'due' => null])->all()],
            ['label' => 'Initiatives', 'url' => InitiativeResource::canViewAny() ? InitiativeResource::getUrl() : null, 'items' => (clone $initiatives)->orderByDesc('updated_at')->limit(5)->get()->map(fn (Initiative $r): array => ['title' => $r->name, 'status' => null, 'due' => null])->all()],
            ['label' => 'Milestones', 'url' => MilestoneResource::canViewAny() ? MilestoneResource::getUrl() : null, 'items' => (clone $milestones)->whereNotNull('target_date')->whereDate('target_date', '>=', now()->toDateString())->whereNotIn('status', ['completed', 'done', 'cancelled', 'canceled'])->orderBy('target_date')->limit(5)->get()->map(fn (Milestone $r): array => ['title' => $r->name, 'status' => $r->status, 'due' => $this->formatDate($r->target_date)])->all()],
            ['label' => 'Tasks', 'url' => TaskResource::canViewAny() ? TaskResource::getUrl() : null, 'items' => (clone $actionableTasks)->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderByDesc('updated_at')->limit(5)->get()->map(fn (Task $r): array => ['title' => $r->name, 'status' => $r->status, 'due' => $this->formatDate($r->due_at)])->all()],
        ];

        $activityAvailable = EventResource::canViewAny();
        $activityDays = [];
        if ($activityAvailable) {
            $timezone = (string) config('app.timezone');
            $now = Carbon::now($timezone);
            $activityRange = $activityRange === 'today' ? 'today' : '14_days';
            $start = $now->copy()->startOfDay()->subDays($activityRange === 'today' ? 0 : 13);

            /** @var Builder<Event> $eventQuery */
            $eventQuery = EventResource::getEloquentQuery();
            $events = $eventQuery
                ->where('events.enterprise_id', $enterpriseId)
                ->where('events.occurred_at', '>=', $start->utc())
                ->where('events.occurred_at', '<=', $now->utc())
                ->with(['actor' => fn ($actorQuery) => $actorQuery->whereHas('memberships', fn (Builder $membershipQuery) => $membershipQuery->where('organization_id', $enterprise->organization_id))])
                ->orderByDesc('events.occurred_at')
                ->orderByDesc('events.id')
                ->limit(100)
                ->get();

            $activityDays = $events
                ->groupBy(fn (Event $event): string => Carbon::parse($event->occurred_at)->timezone($timezone)->toDateString())
                ->map(function (Collection $dayEvents, string $date) use ($timezone): array {
                    return [
                        'date' => Carbon::parse($date, $timezone)->translatedFormat('l, j F'),
                        'events' => $dayEvents->map(function (Event $event) use ($timezone): array {
                            $actor = $event->getRelation('actor');

                            return [
                                'description' => $event->description,
                                'type' => Str::headline(str_replace('.', ' ', $event->event_type)),
                                'actor' => $actor instanceof User ? $actor->name : 'System',
                                'time' => Carbon::parse($event->occurred_at)->timezone($timezone)->format('H:i'),
                            ];
                        })->values()->all(),
                    ];
                })
                ->values()
                ->all();
        }

        $lateTasks = (clone $actionableTasks)->whereNotNull('due_at')->whereDate('due_at', '<', now()->toDateString())->orderBy('due_at')->limit(8)->get();
        $thisWeekTasks = (clone $actionableTasks)->whereNotNull('due_at')->where('due_at', '>=', $startOfWeek)->where('due_at', '<', $startOfNextWeek)->orderBy('due_at')->limit(8)->get();
        $nextWeekTasks = (clone $actionableTasks)->whereNotNull('due_at')->where('due_at', '>=', $startOfNextWeek)->where('due_at', '<', $startOfFollowingWeek)->orderBy('due_at')->limit(8)->get();

        return [
            'summaryCards' => $this->summaryCards($enterprise),
            'strategyOverview' => $this->strategyOverview($enterprise),
            'goalOverview' => $this->goalOverview($enterprise),
            'executionOverview' => $this->executionOverview($enterprise),
            'kpiOverview' => $this->kpiOverview($enterprise),
            'listCards' => $listCards,
            'activityAvailable' => $activityAvailable,
            'activityRangeLabel' => $activityRange === 'today' ? 'Today' : 'Last 14 days',
            'activityDays' => $activityDays,
            'lifecycleChart' => app(LifecycleChartData::class)->forEnterprise($enterprise, $activityRange),
            'activityPanels' => [
                ['label' => 'Late tasks', 'url' => TaskResource::canViewAny() ? TaskResource::getUrl() : null, 'items' => $lateTasks->map(fn (Task $r): array => ['title' => $r->name, 'status' => $r->status, 'due' => $this->formatDate($r->due_at)])->all()],
                ['label' => 'Tasks due this week', 'url' => TaskResource::canViewAny() ? TaskResource::getUrl() : null, 'items' => $thisWeekTasks->map(fn (Task $r): array => ['title' => $r->name, 'status' => $r->status, 'due' => $this->formatDate($r->due_at)])->all()],
                ['label' => 'Tasks due next week', 'url' => TaskResource::canViewAny() ? TaskResource::getUrl() : null, 'items' => $nextWeekTasks->map(fn (Task $r): array => ['title' => $r->name, 'status' => $r->status, 'due' => $this->formatDate($r->due_at)])->all()],
            ],
        ];
    }

    /** @return array{count: int|null, items: array<int, array{name: string, description: string|null, url: string}>} */
    private function strategyOverview(Enterprise $enterprise): array
    {
        if (! StrategyResource::canViewAny()) {
            return ['count' => null, 'items' => []];
        }

        $query = StrategyResource::getEloquentQuery()
            ->whereHas('objective', fn (Builder $builder) => $builder->where('enterprise_id', $enterprise->id));

        /** @var \Illuminate\Database\Eloquent\Collection<int, Strategy> $strategies */
        $strategies = $query->with('objective')->orderByDesc('strategies.updated_at')->limit(3)->get();

        return [
            'count' => (clone $query)->count(),
            'items' => $strategies->map(fn (Strategy $strategy): array => [
                'name' => $strategy->name,
                'description' => $strategy->description,
                'url' => StrategyResource::getUrl('edit', ['record' => $strategy]),
            ])->all(),
        ];
    }

    /** @return array{count: int|null, objective_count: int|null, items: array<int, array{name: string, description: string|null, status: string}>} */
    private function goalOverview(Enterprise $enterprise): array
    {
        if (! GoalResource::canViewAny()) {
            return ['count' => null, 'objective_count' => null, 'items' => []];
        }

        $goals = GoalResource::getEloquentQuery()->where('enterprise_id', $enterprise->id);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Goal> $goalItems */
        $goalItems = (clone $goals)->orderByDesc('updated_at')->limit(3)->get();

        return [
            'count' => (clone $goals)->count(),
            'objective_count' => ObjectiveResource::canViewAny()
                ? ObjectiveResource::getEloquentQuery()->where('enterprise_id', $enterprise->id)->count()
                : null,
            'items' => $goalItems->map(fn (Goal $goal): array => [
                'name' => $goal->name,
                'description' => $goal->description,
                'status' => $goal->status,
            ])->all(),
        ];
    }

    /** @return array{projects: int|null, initiatives: int|null} */
    private function executionOverview(Enterprise $enterprise): array
    {
        return [
            'projects' => ProjectResource::canViewAny()
                ? ProjectResource::getEloquentQuery()->where('enterprise_id', $enterprise->id)->count()
                : null,
            'initiatives' => InitiativeResource::canViewAny()
                ? InitiativeResource::getEloquentQuery()->where('enterprise_id', $enterprise->id)->count()
                : null,
        ];
    }

    /** @return array{count: int|null, items: array<int, array{name: string, definition: string, unit: string|null, current: float|null, target: float|null, url: string}>} */
    private function kpiOverview(Enterprise $enterprise): array
    {
        if (! KpiResource::canViewAny()) {
            return ['count' => null, 'items' => []];
        }

        $query = KpiResource::getEloquentQuery()->where('enterprise_id', $enterprise->id);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Kpi> $kpis */
        $kpis = $query->orderByDesc('updated_at')->limit(6)->get();

        return [
            'count' => (clone $query)->count(),
            'items' => $kpis->map(function (Kpi $kpi): array {
                $target = is_numeric($kpi->target_value) ? (float) $kpi->target_value : null;
                $current = is_numeric($kpi->current_value) ? (float) $kpi->current_value : null;

                return [
                    'name' => $kpi->name,
                    'definition' => $kpi->definition,
                    'unit' => $kpi->unit,
                    'current' => $current,
                    'target' => $target,
                    'url' => KpiResource::getUrl('edit', ['record' => $kpi]),
                ];
            })->all(),
        ];
    }

    /** @return array<int, array{label: string, count: int|null, url: string|null}> */
    public function summaryCards(Enterprise $enterprise): array
    {
        $enterpriseId = (int) $enterprise->id;

        return [
            ['label' => 'Strategies', 'count' => StrategyResource::canViewAny() ? StrategyResource::getEloquentQuery()->whereHas('objective', fn (Builder $q) => $q->where('enterprise_id', $enterpriseId))->count() : null, 'url' => StrategyResource::canViewAny() ? StrategyResource::getUrl() : null],
            ['label' => 'Goals', 'count' => GoalResource::canViewAny() ? GoalResource::getEloquentQuery()->where('enterprise_id', $enterpriseId)->count() : null, 'url' => GoalResource::canViewAny() ? GoalResource::getUrl() : null],
            ['label' => 'Objectives', 'count' => ObjectiveResource::canViewAny() ? ObjectiveResource::getEloquentQuery()->where('enterprise_id', $enterpriseId)->count() : null, 'url' => ObjectiveResource::canViewAny() ? ObjectiveResource::getUrl() : null],
            ['label' => 'Initiatives', 'count' => InitiativeResource::canViewAny() ? InitiativeResource::getEloquentQuery()->where('enterprise_id', $enterpriseId)->count() : null, 'url' => InitiativeResource::canViewAny() ? InitiativeResource::getUrl() : null],
            ['label' => 'KPIs', 'count' => KpiResource::canViewAny() ? KpiResource::getEloquentQuery()->where('enterprise_id', $enterpriseId)->count() : null, 'url' => KpiResource::canViewAny() ? KpiResource::getUrl() : null],
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
