<?php

namespace App\Services\Lifecycle;

use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Issue;
use App\Models\LifecycleSnapshot;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class LifecycleHistoryService
{
    private const CLOSED_STATES = ['closed', 'complete', 'completed', 'done', 'cancelled', 'canceled', 'archived', 'deleted', 'rejected'];

    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const ENTITIES = [
        'goals' => Goal::class,
        'objectives' => Objective::class,
        'tasks' => Task::class,
        'milestones' => Milestone::class,
        'issues' => Issue::class,
    ];

    /** Capture the previous day's end and the current day's start at the same midnight boundary. */
    public function captureMidnight(?CarbonImmutable $capturedAt = null): int
    {
        $capturedAt ??= CarbonImmutable::now(config('app.timezone'));
        $today = $capturedAt->startOfDay();
        $yesterday = $today->subDay();
        $boundaryAt = $today;
        $withinCaptureWindow = $capturedAt->greaterThanOrEqualTo($boundaryAt)
            && abs((int) $capturedAt->diffInSeconds($boundaryAt)) <= 120;
        $count = 0;

        Enterprise::query()->with('organization')->orderBy('id')->each(function (Enterprise $enterprise) use ($capturedAt, $today, $yesterday, $boundaryAt, $withinCaptureWindow, &$count): void {
            foreach ([
                [$today, LifecycleSnapshot::BOUNDARY_START],
                [$yesterday, LifecycleSnapshot::BOUNDARY_END],
            ] as [$date, $boundary]) {
                foreach (self::ENTITIES as $entityType => $modelClass) {
                    $this->captureOne($enterprise, $modelClass, $entityType, $date, $boundary, $boundaryAt, $capturedAt, $withinCaptureWindow);
                    $count++;
                }
            }
        });

        return $count;
    }

    /** @return array{available: bool, reason: string|null, history_available_from: string|null, points: list<array{date: string, open_at_start: int, open_at_end: int, opened: int, closed: int}>} */
    public function dailySeries(User $actor, Enterprise $enterprise, string $entityType, CarbonImmutable $from, CarbonImmutable $to): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if (! isset(self::ENTITIES[$entityType])) {
            return ['available' => false, 'reason' => 'unsupported_entity_type', 'history_available_from' => null, 'points' => []];
        }

        $historyAvailableFrom = $this->historyAvailableFrom($enterprise, $entityType);
        $timezone = (string) config('app.timezone');
        $start = $from->setTimezone($timezone)->startOfDay();
        $end = $to->setTimezone($timezone)->startOfDay();
        if ($end->lessThan($start)) {
            return ['available' => false, 'reason' => 'invalid_date_range', 'history_available_from' => $historyAvailableFrom, 'points' => []];
        }

        $snapshots = LifecycleSnapshot::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->id)
            ->where('entity_type', $entityType)
            ->whereDate('snapshot_date', '>=', $start->toDateString())
            ->whereDate('snapshot_date', '<=', $end->toDateString())
            ->whereIn('boundary', [LifecycleSnapshot::BOUNDARY_START, LifecycleSnapshot::BOUNDARY_END])
            ->get(['snapshot_date', 'boundary', 'open_count', 'is_complete'])
            ->keyBy(fn (LifecycleSnapshot $snapshot): string => CarbonImmutable::parse($snapshot->getAttribute('snapshot_date'), $timezone)->toDateString().'|'.$snapshot->boundary);

        $modelClass = self::ENTITIES[$entityType];
        $morph = (new $modelClass)->getMorphClass();
        $eventsByDay = Event::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->id)
            ->where('subject_type', $morph)
            ->whereIn('event_type', ['open', 'closed'])
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end->addDay())
            ->get(['event_type', 'occurred_at'])
            ->groupBy(fn (Event $event): string => CarbonImmutable::parse($event->occurred_at, $timezone)->toDateString());

        $points = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $dayKey = $day->toDateString();
            $startSnapshot = $snapshots->get($dayKey.'|'.LifecycleSnapshot::BOUNDARY_START);
            $endSnapshot = $snapshots->get($dayKey.'|'.LifecycleSnapshot::BOUNDARY_END);

            if (! $startSnapshot instanceof LifecycleSnapshot || ! $endSnapshot instanceof LifecycleSnapshot || ! $startSnapshot->is_complete || ! $endSnapshot->is_complete) {
                return ['available' => false, 'reason' => 'missing_or_incomplete_boundary_snapshot', 'history_available_from' => $historyAvailableFrom, 'points' => []];
            }

            $events = $eventsByDay->get($dayKey, collect());
            $opened = $events->where('event_type', 'open')->count();
            $closed = $events->where('event_type', 'closed')->count();
            $openAtStart = (int) $startSnapshot->open_count;
            $openAtEnd = (int) $endSnapshot->open_count;

            if ($openAtEnd !== $openAtStart + $opened - $closed) {
                return ['available' => false, 'reason' => 'daily_reconciliation_failed', 'history_available_from' => $historyAvailableFrom, 'points' => []];
            }

            $points[] = [
                'date' => $dayKey,
                'open_at_start' => $openAtStart,
                'open_at_end' => $openAtEnd,
                'opened' => $opened,
                'closed' => $closed,
            ];
        }

        return ['available' => true, 'reason' => null, 'history_available_from' => $historyAvailableFrom, 'points' => $points];
    }

    /** @return array{available: bool, reason: string|null, history_available_from: string|null, points: list<array{date: string, open_at_start: int, open_at_end: int, opened: int, closed: int}>} */
    public function recentDailySeries(User $actor, Enterprise $enterprise, string $entityType, int $days = 14): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if (! isset(self::ENTITIES[$entityType])) {
            return ['available' => false, 'reason' => 'unsupported_entity_type', 'history_available_from' => null, 'points' => []];
        }

        $timezone = (string) config('app.timezone');
        $latestAllowedDate = CarbonImmutable::now($timezone)->startOfDay()->subDay();
        $windowStart = $latestAllowedDate->subDays(max(1, $days) - 1);
        $snapshots = LifecycleSnapshot::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->id)
            ->where('entity_type', $entityType)
            ->whereDate('snapshot_date', '>=', $windowStart->toDateString())
            ->whereDate('snapshot_date', '<=', $latestAllowedDate->toDateString())
            ->whereIn('boundary', [LifecycleSnapshot::BOUNDARY_START, LifecycleSnapshot::BOUNDARY_END])
            ->get(['snapshot_date', 'boundary', 'is_complete'])
            ->keyBy(fn (LifecycleSnapshot $snapshot): string => CarbonImmutable::parse($snapshot->getAttribute('snapshot_date'), $timezone)->toDateString().'|'.$snapshot->boundary);

        $latestCompleteDate = null;
        for ($day = $latestAllowedDate; $day->greaterThanOrEqualTo($windowStart); $day = $day->subDay()) {
            $daySnapshots = $snapshots->get($day->toDateString());
            $startSnapshot = $snapshots->get($day->toDateString().'|'.LifecycleSnapshot::BOUNDARY_START);
            $endSnapshot = $snapshots->get($day->toDateString().'|'.LifecycleSnapshot::BOUNDARY_END);
            if ($startSnapshot instanceof LifecycleSnapshot && $endSnapshot instanceof LifecycleSnapshot && $startSnapshot->is_complete && $endSnapshot->is_complete) {
                $latestCompleteDate = $day;
                break;
            }
        }

        if ($latestCompleteDate === null) {
            return [
                'available' => false,
                'reason' => $entityType === 'issues' ? 'github_transition_coverage_unverified' : 'no_complete_daily_snapshots',
                'history_available_from' => $this->historyAvailableFrom($enterprise, $entityType),
                'points' => [],
            ];
        }

        $oldestCompleteDate = $latestCompleteDate;
        for ($day = $latestCompleteDate->subDay(); $day->greaterThanOrEqualTo($windowStart); $day = $day->subDay()) {
            $startSnapshot = $snapshots->get($day->toDateString().'|'.LifecycleSnapshot::BOUNDARY_START);
            $endSnapshot = $snapshots->get($day->toDateString().'|'.LifecycleSnapshot::BOUNDARY_END);
            if (! $startSnapshot instanceof LifecycleSnapshot || ! $endSnapshot instanceof LifecycleSnapshot || ! $startSnapshot->is_complete || ! $endSnapshot->is_complete) {
                break;
            }
            $oldestCompleteDate = $day;
        }

        return $this->dailySeries($actor, $enterprise, $entityType, $oldestCompleteDate, $latestCompleteDate);
    }

    private function historyAvailableFrom(Enterprise $enterprise, string $entityType): ?string
    {
        $date = LifecycleSnapshot::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->id)
            ->where('entity_type', $entityType)
            ->where('boundary', LifecycleSnapshot::BOUNDARY_START)
            ->where('is_complete', true)
            ->orderBy('snapshot_date')
            ->value('snapshot_date');

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return is_string($date) ? substr($date, 0, 10) : null;
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
    private function captureOne(
        Enterprise $enterprise,
        string $modelClass,
        string $entityType,
        CarbonImmutable $date,
        string $boundary,
        CarbonImmutable $boundaryAt,
        CarbonImmutable $capturedAt,
        bool $withinCaptureWindow,
    ): LifecycleSnapshot {
        try {
            return DB::transaction(function () use ($enterprise, $modelClass, $entityType, $date, $boundary, $boundaryAt, $capturedAt, $withinCaptureWindow): LifecycleSnapshot {
                $stateField = $modelClass === Issue::class ? 'state' : 'status';
                $query = $modelClass::query()->where('enterprise_id', $enterprise->id);
                $query = $modelClass === Issue::class
                    ? $query->where($stateField, 'open')
                    : $query->whereNotIn($stateField, self::CLOSED_STATES);

                $openCountAtCapture = (int) $query->count();
                $isComplete = $withinCaptureWindow;
                $metadata = [
                    'capture_method' => 'midnight_state_reconciled_with_lifecycle_events',
                    'capture_window_seconds' => 120,
                    'source' => $modelClass === Issue::class ? 'github_sync_observation' : 'cr8or_lifecycle_events',
                ];

                if ($modelClass === Issue::class) {
                    $lastSyncValue = $enterprise->getAttribute('github_issues_synced_at');
                    $lastSync = $lastSyncValue instanceof \DateTimeInterface
                        ? CarbonImmutable::instance($lastSyncValue)
                        : (is_string($lastSyncValue) ? CarbonImmutable::parse($lastSyncValue) : null);
                    $freshEnough = $lastSync !== null && $lastSync->greaterThanOrEqualTo($boundaryAt->subMinutes(5));
                    $metadata['sync_fresh_at_boundary'] = $freshEnough;
                    $metadata['last_sync_observed_at'] = $lastSync?->toIso8601String();
                    $metadata['transition_coverage_verified'] = false;
                    $metadata['completeness_reason'] = 'github_transition_coverage_unverified';

                    // A current-state sync cannot prove that no close/reopen transitions were missed between observations.
                    $isComplete = false;
                }

                $transitionsAfterBoundary = Event::query()
                    ->where('organization_id', $enterprise->organization_id)
                    ->where('enterprise_id', $enterprise->id)
                    ->where('subject_type', (new $modelClass)->getMorphClass())
                    ->whereIn('event_type', ['open', 'closed'])
                    ->where('occurred_at', '>=', $boundaryAt)
                    ->where('occurred_at', '<=', $capturedAt)
                    ->get(['event_type']);

                $openAtBoundary = $openCountAtCapture
                    - $transitionsAfterBoundary->where('event_type', 'open')->count()
                    + $transitionsAfterBoundary->where('event_type', 'closed')->count();
                if ($openAtBoundary < 0) {
                    $isComplete = false;
                    $openAtBoundary = 0;
                    $metadata['reconciliation_error'] = 'negative_boundary_count';
                }

                if (abs((int) $capturedAt->diffInSeconds($boundaryAt)) > 120) {
                    $metadata['late_capture'] = true;
                }

                $snapshot = LifecycleSnapshot::query()
                    ->where('enterprise_id', $enterprise->id)
                    ->whereDate('snapshot_date', $date->toDateString())
                    ->where('boundary', $boundary)
                    ->where('entity_type', $entityType)
                    ->lockForUpdate()
                    ->first();

                if ($snapshot instanceof LifecycleSnapshot && $snapshot->is_complete) {
                    return $snapshot;
                }

                if (! $snapshot instanceof LifecycleSnapshot) {
                    $snapshot = new LifecycleSnapshot([
                        'enterprise_id' => $enterprise->id,
                        'snapshot_date' => $date->toDateString(),
                        'boundary' => $boundary,
                        'entity_type' => $entityType,
                    ]);
                }

                $snapshot->fill([
                    'organization_id' => $enterprise->organization_id,
                    'open_count' => $openAtBoundary,
                    'is_complete' => $isComplete,
                    'metadata' => $metadata,
                    'boundary_at' => $boundaryAt,
                    'captured_at' => $capturedAt,
                ])->save();

                return $snapshot;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $snapshot = LifecycleSnapshot::query()
                ->where('organization_id', $enterprise->organization_id)
                ->where('enterprise_id', $enterprise->id)
                ->whereDate('snapshot_date', $date->toDateString())
                ->where('boundary', $boundary)
                ->where('entity_type', $entityType)
                ->first();

            if ($snapshot instanceof LifecycleSnapshot) {
                return $snapshot;
            }

            throw $exception;
        }
    }
}
