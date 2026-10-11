<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('keeps generated and lifecycle-owned records outside generic CRUD mutations', function () {
    $user = User::factory()->create();
    $models = [
        App\Models\AgentDecision::class,
        App\Models\AgentDelegation::class,
        App\Models\AgentExecution::class,
        App\Models\AgentEpisodicMemory::class,
        App\Models\AgentExecutionEventRecord::class,
        App\Models\AgentExecutionStep::class,
        App\Models\AgentSemanticMemory::class,
        App\Models\AgentSemanticMemoryVersion::class,
        App\Models\ApprovalDecision::class,
        App\Models\ApprovalRequest::class,
        App\Models\AssetVersion::class,
        App\Models\BusinessHealthResult::class,
        App\Models\CommandWebhookDelivery::class,
        App\Models\Event::class,
        App\Models\ExternalResource::class,
        App\Models\FinancialReport::class,
        App\Models\GenerationJob::class,
        App\Models\GenerationRequest::class,
        App\Models\IntegrationJob::class,
        App\Models\IntegrationResult::class,
        App\Models\Issue::class,
        App\Models\KnowledgeEmbedding::class,
        App\Models\KnowledgeIndexRecord::class,
        App\Models\KnowledgeIndexUnit::class,
        App\Models\MediaMetadata::class,
        App\Models\PublicationResult::class,
        App\Models\PublishingJob::class,
        App\Models\RenderJob::class,
        App\Models\RenderOutput::class,
        App\Models\RenderRequest::class,
        App\Models\Report::class,
        App\Models\ReportMetricValue::class,
        App\Models\ReportSnapshot::class,
        App\Models\Transformation::class,
        App\Models\WorkflowExecution::class,
        App\Models\WorkflowStage::class,
        App\Models\WorkflowVersion::class,
    ];

    foreach ($models as $model) {
        $record = new $model;

        expect(Gate::forUser($user)->allows('create', $model))->toBeFalse("{$model} must use its domain creation operation")
            ->and(Gate::forUser($user)->allows('update', $record))->toBeFalse("{$model} must not allow generic updates")
            ->and(Gate::forUser($user)->allows('delete', $record))->toBeFalse("{$model} must not allow generic deletes");
    }
});

it('requires versioned strategic records to be replaced rather than updated or deleted', function () {
    $user = User::factory()->create();

    foreach ([App\Models\Competitor::class, App\Models\Mission::class, App\Models\Vision::class] as $model) {
        $record = new $model;

        expect(Gate::forUser($user)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $record))->toBeFalse();
    }
});
