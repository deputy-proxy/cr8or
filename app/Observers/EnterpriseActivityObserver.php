<?php

namespace App\Observers;

use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Decision;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\EnterpriseDecision;
use App\Models\Goal;
use App\Models\Initiative;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use App\Models\Issue;
use App\Models\Kpi;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\PublicationSchedule;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\WorkflowExecution;
use App\Services\EnterpriseEventRecorder;
use App\Support\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class EnterpriseActivityObserver
{
    /** @var array<class-string<Model>, list<string>> */
    private const TRACKED_FIELDS = [
        Enterprise::class => ['name', 'status', 'enterprise_group_id', 'enterprise_category_id'],
        EnterpriseContext::class => ['description', 'industry', 'business_model', 'target_market', 'geography'],
        Strategy::class => ['name', 'description'],
        Initiative::class => ['name', 'description'],
        Goal::class => ['name', 'status'],
        Objective::class => ['name', 'status'],
        Kpi::class => ['name', 'definition', 'unit', 'target_value', 'current_value', 'status'],
        Project::class => ['name', 'status'],
        Task::class => ['name', 'status', 'priority', 'due_at'],
        Milestone::class => ['name', 'status', 'due_at'],
        Decision::class => ['title', 'summary', 'rationale'],
        EnterpriseDecision::class => ['title', 'summary', 'rationale', 'decided_at', 'actor_id', 'actor_name'],
        Dependency::class => ['type', 'project_id', 'predecessor_type', 'predecessor_id', 'successor_type', 'successor_id'],
        Assignment::class => ['assignable_type', 'assignable_id', 'user_id', 'agent_assignment_id'],
        Issue::class => ['title'],
        Campaign::class => ['name', 'status'],
        ContentItem::class => ['title', 'status'],
        Publication::class => ['status', 'scheduled_at', 'submitted_at', 'published_at'],
        PublicationResult::class => ['provider_status', 'failure_code'],
        PublicationSchedule::class => ['status', 'scheduled_at'],
        IntegrationConnection::class => ['status'],
        IntegrationJob::class => ['status', 'failure_code'],
        IntegrationResult::class => ['status', 'processing_status'],
        WorkflowExecution::class => ['status', 'current_stage_key', 'completed_at'],
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $tracked = self::TRACKED_FIELDS[$model::class] ?? [];
        $changed = $model instanceof Issue
            ? array_values(array_intersect(['title', 'state'], array_keys($model->getChanges())))
            : array_values(array_intersect($tracked, array_keys($model->getChanges())));

        if ($changed === []) {
            return;
        }

        $stateField = $model instanceof Issue ? 'state' : 'status';
        $stateChanged = in_array($stateField, $changed, true);
        $lifecycleTransition = false;
        $oldState = null;
        $newState = null;

        if ($this->supportsLifecycle($model) && $stateChanged) {
            $oldState = $model->getRawOriginal($stateField);
            $newState = $model->getAttribute($stateField);
            $wasOpen = $this->isOpenState(is_string($oldState) ? $oldState : null);
            $isOpen = $this->isOpenState(is_string($newState) ? $newState : null);
            $lifecycleTransition = $wasOpen !== $isOpen;
        }

        $fieldsToRecord = $lifecycleTransition ? array_values(array_diff($changed, [$stateField])) : $changed;
        if ($fieldsToRecord !== []) {
            $statusChanged = in_array('status', $fieldsToRecord, true) || in_array('state', $fieldsToRecord, true);
            $this->record($model, $statusChanged ? 'status_changed' : 'updated', $fieldsToRecord);
        }

        if ($lifecycleTransition) {
            $this->recordLifecycle(
                $model,
                $this->isOpenState(is_string($newState) ? $newState : null) ? 'open' : 'closed',
                is_string($oldState) ? $oldState : null,
                is_string($newState) ? $newState : null,
            );
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Enterprise || ! array_key_exists($model::class, self::TRACKED_FIELDS)) {
            return;
        }

        $this->record($model, 'deleted');
    }

    private function supportsLifecycle(Model $model): bool
    {
        return $model instanceof Goal
            || $model instanceof Objective
            || $model instanceof Task
            || $model instanceof Milestone
            || $model instanceof Issue;
    }

    private function isOpenState(?string $state): bool
    {
        if ($state === null || trim($state) === '') {
            return false;
        }

        return ! LifecycleStatus::isClosed($state);
    }

    private function recordLifecycle(Model $model, string $transition, ?string $fromState, ?string $toState): void
    {
        $enterprise = $this->enterpriseFor($model);
        if ($enterprise === null) {
            return;
        }

        $sourceTime = $model instanceof Issue
            ? ($transition === 'closed' ? $model->getAttribute('github_closed_at') : $model->getAttribute('github_updated_at'))
            : $model->getAttribute('updated_at');
        $occurredAt = $sourceTime instanceof Carbon ? $sourceTime : Carbon::parse($sourceTime ?? now());
        $label = $model->getAttribute('name') ?? $model->getAttribute('title') ?? class_basename($model);
        $sourceIdentityTime = $occurredAt->format('Y-m-d\TH:i:s.uP');
        $sourceEventId = $model instanceof Issue
            ? implode(':', ['lifecycle', $model->getMorphClass(), (string) $model->getKey(), $fromState ?? 'initial', $transition, $sourceIdentityTime])
            : (string) Str::uuid();

        app(EnterpriseEventRecorder::class)->record(
            organizationId: (int) $enterprise->organization_id,
            enterpriseId: (int) $enterprise->id,
            source: 'cr8or',
            eventType: $transition,
            description: class_basename($model).' "'.Str::limit((string) $label, 120, '').'" '.$transition,
            occurredAt: $occurredAt,
            payload: [
                'entity_type' => class_basename($model),
                'entity_id' => (int) $model->getKey(),
                'from_state' => $fromState,
                'to_state' => $toState,
                'source' => $model instanceof Issue ? 'github_sync' : 'cr8or',
            ],
            sourceEventId: $sourceEventId,
            actorId: Auth::id() === null ? null : (int) Auth::id(),
            subjectType: $model->getMorphClass(),
            subjectId: (string) $model->getKey(),
            correlationId: is_string($model->getAttribute('correlation_id')) ? $model->getAttribute('correlation_id') : null,
        );
    }

    /** @param list<string> $changedFields */
    private function record(Model $model, string $action, array $changedFields = []): void
    {
        $enterprise = $this->enterpriseFor($model);
        if ($enterprise === null) {
            return;
        }

        $label = $model->getAttribute('name') ?? $model->getAttribute('title') ?? class_basename($model);
        $status = $model instanceof Issue ? $model->getAttribute('state') : $model->getAttribute('status');
        $description = match ($action) {
            'created' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" created',
            'deleted' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" deleted',
            'status_changed' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" status changed to '.(string) $status,
            default => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" updated',
        };

        app(EnterpriseEventRecorder::class)->record(
            organizationId: (int) $enterprise->organization_id,
            enterpriseId: (int) $enterprise->id,
            source: 'cr8or',
            eventType: Str::snake(class_basename($model)).'.'.$action,
            description: $description,
            occurredAt: Carbon::now(),
            payload: [
                'action' => $action,
                'entity_type' => class_basename($model),
                'entity_id' => (int) $model->getKey(),
                'name' => Str::limit((string) $label, 150, ''),
                'status' => is_string($status) ? $status : null,
                'changed_fields' => $changedFields,
            ],
            sourceEventId: (string) Str::uuid(),
            actorId: ($model instanceof Decision || $model instanceof EnterpriseDecision) && is_numeric($model->getAttribute('actor_id'))
                ? (int) $model->getAttribute('actor_id')
                : (Auth::id() === null ? null : (int) Auth::id()),
            subjectType: $model->getMorphClass(),
            subjectId: (string) $model->getKey(),
            correlationId: is_string($model->getAttribute('correlation_id')) ? $model->getAttribute('correlation_id') : null,
        );
    }

    private function enterpriseFor(Model $model): ?Enterprise
    {
        if ($model instanceof Enterprise) {
            return $model;
        }

        $enterpriseId = $model->getAttribute('enterprise_id');
        if (is_numeric($enterpriseId)) {
            return Enterprise::query()->find((int) $enterpriseId);
        }

        $enterpriseId = match (true) {
            $model instanceof Strategy => $model->objective()->value('enterprise_id'),
            $model instanceof Initiative => Objective::query()
                ->whereHas('strategies', fn ($query) => $query->whereKey($model->strategy_id))
                ->value('enterprise_id'),
            default => null,
        };

        return is_numeric($enterpriseId) ? Enterprise::query()->find((int) $enterpriseId) : null;
    }
}
