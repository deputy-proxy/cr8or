<?php

namespace App\Observers;

use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Goal;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use App\Models\Issue;
use App\Models\Milestone;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\PublicationSchedule;
use App\Models\Task;
use App\Models\WorkflowExecution;
use App\Services\EnterpriseEventRecorder;
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
        Project::class => ['name', 'status'],
        Goal::class => ['name', 'status'],
        Objective::class => ['name', 'status'],
        Task::class => ['name', 'status', 'priority', 'due_at'],
        Milestone::class => ['name', 'status', 'due_at'],
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

        if ($this->supportsLifecycle($model) && ! $model instanceof Issue && $this->isOpenState($this->stateValue($model))) {
            $this->recordLifecycle($model, 'open', null, $this->stateValue($model));
        }
    }

    public function updated(Model $model): void
    {
        $tracked = self::TRACKED_FIELDS[$model::class] ?? [];
        if ($model instanceof Issue) {
            $changed = in_array('state', array_keys($model->getChanges()), true) ? ['state'] : [];
        } else {
            $changed = array_values(array_intersect($tracked, array_keys($model->getChanges())));
        }

        if ($changed !== []) {
            $this->record($model, in_array('status', $changed, true) || in_array('state', $changed, true) ? 'status_changed' : 'updated');
        }

        if (! $this->supportsLifecycle($model) || ! in_array($model instanceof Issue ? 'state' : 'status', array_keys($model->getChanges()), true)) {
            return;
        }

        $stateField = $model instanceof Issue ? 'state' : 'status';
        $oldState = $model->getRawOriginal($stateField);
        $newState = $model->getAttribute($stateField);
        $wasOpen = $this->isOpenState(is_string($oldState) ? $oldState : null);
        $isOpen = $this->isOpenState(is_string($newState) ? $newState : null);

        if ($wasOpen !== $isOpen) {
            $this->recordLifecycle($model, $isOpen ? 'open' : 'closed', is_string($oldState) ? $oldState : null, is_string($newState) ? $newState : null);
        }
    }

    private function supportsLifecycle(Model $model): bool
    {
        return $model instanceof Goal
            || $model instanceof Objective
            || $model instanceof Task
            || $model instanceof Milestone
            || $model instanceof Issue;
    }

    private function stateValue(Model $model): ?string
    {
        $field = $model instanceof Issue ? 'state' : 'status';
        $state = $model->getAttribute($field);

        return is_string($state) ? $state : null;
    }

    private function isOpenState(?string $state): bool
    {
        if ($state === null || trim($state) === '') {
            return false;
        }

        return ! in_array(strtolower(trim($state)), ['closed', 'complete', 'completed', 'done', 'cancelled', 'canceled', 'archived', 'deleted', 'rejected'], true);
    }

    private function recordLifecycle(Model $model, string $transition, ?string $fromState, ?string $toState): void
    {
        $enterpriseId = (int) ($model instanceof Enterprise ? $model->getKey() : ($model->enterprise_id ?? 0));
        if ($enterpriseId <= 0) {
            return;
        }

        $enterprise = Enterprise::query()->find($enterpriseId);
        if ($enterprise === null) {
            return;
        }

        $sourceTime = $model instanceof Issue
            ? ($transition === 'closed' ? $model->getAttribute('github_closed_at') : $model->getAttribute('github_updated_at'))
            : $model->getAttribute('updated_at');
        $occurredAt = $sourceTime instanceof Carbon ? $sourceTime : Carbon::parse($sourceTime ?? now());
        $label = $model->getAttribute('name') ?? $model->getAttribute('title') ?? class_basename($model);
        $sourceIdentityTime = $occurredAt->format('Y-m-d\TH:i:s.uP');
        $sourceEventId = implode(':', ['lifecycle', $model->getMorphClass(), (string) $model->getKey(), $fromState ?? 'initial', $transition, $sourceIdentityTime]);

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

    private function record(Model $model, string $action): void
    {
        $enterpriseId = $model instanceof Enterprise ? (int) $model->getKey() : (int) ($model->enterprise_id ?? 0);
        if ($enterpriseId <= 0) {
            return;
        }

        $enterprise = $model instanceof Enterprise ? $model : Enterprise::query()->find($enterpriseId);
        if ($enterprise === null) {
            return;
        }

        $label = $model->getAttribute('name') ?? $model->getAttribute('title') ?? class_basename($model);
        $status = $model instanceof Issue ? $model->getAttribute('state') : $model->getAttribute('status');
        $description = match ($action) {
            'created' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" created',
            'status_changed' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" status changed to '.(string) $status,
            default => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" updated',
        };

        $updatedAt = $model->getAttribute('updated_at');
        $version = $updatedAt instanceof Carbon ? $updatedAt->format(DATE_ATOM) : (string) $updatedAt;

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
            ],
            sourceEventId: implode(':', ['model', $model->getMorphClass(), (string) $model->getKey(), $action, $version]),
            actorId: Auth::id() === null ? null : (int) Auth::id(),
            subjectType: $model->getMorphClass(),
            subjectId: (string) $model->getKey(),
            correlationId: is_string($model->getAttribute('correlation_id')) ? $model->getAttribute('correlation_id') : null,
        );
    }
}
