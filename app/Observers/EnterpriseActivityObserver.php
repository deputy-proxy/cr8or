<?php

namespace App\Observers;

use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\PublicationSchedule;
use App\Models\Task;
use App\Models\WorkflowExecution;
use App\Models\WorkItem;
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
        Task::class => ['name', 'status', 'priority', 'due_at'],
        WorkItem::class => ['name', 'status'],
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
    }

    public function updated(Model $model): void
    {
        $tracked = self::TRACKED_FIELDS[$model::class] ?? [];
        $changed = array_values(array_intersect($tracked, array_keys($model->getChanges())));
        if ($changed === []) {
            return;
        }

        $this->record($model, in_array('status', $changed, true) ? 'status_changed' : 'updated');
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
        $description = match ($action) {
            'created' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" created',
            'status_changed' => class_basename($model).' "'.Str::limit((string) $label, 120, '').'" status changed to '.(string) $model->getAttribute('status'),
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
                'status' => is_string($model->getAttribute('status')) ? $model->getAttribute('status') : null,
            ],
            sourceEventId: implode(':', ['model', $model->getMorphClass(), (string) $model->getKey(), $action, $version]),
            actorId: Auth::id() === null ? null : (int) Auth::id(),
            subjectType: $model->getMorphClass(),
            subjectId: (string) $model->getKey(),
            correlationId: is_string($model->getAttribute('correlation_id')) ? $model->getAttribute('correlation_id') : null,
        );
    }
}
