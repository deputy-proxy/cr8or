<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('executes ownership-scoped list queries for resources using the shared scope', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $this->actingAs($user);

    $resources = [
        App\Filament\Resources\AgentEpisodicMemories\AgentEpisodicMemoryResource::class,
        App\Filament\Resources\AgentExecutionEventRecords\AgentExecutionEventRecordResource::class,
        App\Filament\Resources\AgentExecutionSteps\AgentExecutionStepResource::class,
        App\Filament\Resources\AgentSemanticMemories\AgentSemanticMemoryResource::class,
        App\Filament\Resources\AgentSemanticMemoryVersions\AgentSemanticMemoryVersionResource::class,
        App\Filament\Resources\ApprovalDecisions\ApprovalDecisionResource::class,
        App\Filament\Resources\ApprovalPolicies\ApprovalPolicyResource::class,
        App\Filament\Resources\CommandWebhookDeliveries\CommandWebhookDeliveryResource::class,
        App\Filament\Resources\IntegrationResults\IntegrationResultResource::class,
        App\Filament\Resources\KnowledgeEmbeddings\KnowledgeEmbeddingResource::class,
        App\Filament\Resources\KnowledgeIndexRecords\KnowledgeIndexRecordResource::class,
        App\Filament\Resources\KnowledgeIndexUnits\KnowledgeIndexUnitResource::class,
        App\Filament\Resources\ReportMetricValues\ReportMetricValueResource::class,
        App\Filament\Resources\ReportSnapshots\ReportSnapshotResource::class,
        App\Filament\Resources\Reports\ReportResource::class,
        App\Filament\Resources\WorkflowExecutions\WorkflowExecutionResource::class,
        App\Filament\Resources\WorkflowStages\WorkflowStageResource::class,
        App\Filament\Resources\WorkflowVersions\WorkflowVersionResource::class,
    ];

    foreach ($resources as $resource) {
        expect(fn () => $resource::getEloquentQuery()->count())
            ->not->toThrow(Throwable::class, $resource);
    }
});

it('excludes report records owned by another organization from the list query', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterpriseA = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $enterpriseB = Enterprise::factory()->create(['organization_id' => $organizationB->id]);
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organizationA->id]);

    $reportA = App\Models\Report::create([
        'enterprise_id' => $enterpriseA->id,
        'report_type' => 'test',
        'status' => App\Models\Report::STATUS_COMPLETED,
        'period_start' => now()->subDay(),
        'period_end' => now(),
        'generated_at' => now(),
        'methodology_version' => 'test-v1',
    ]);
    $reportB = App\Models\Report::create([
        'enterprise_id' => $enterpriseB->id,
        'report_type' => 'test',
        'status' => App\Models\Report::STATUS_COMPLETED,
        'period_start' => now()->subDay(),
        'period_end' => now(),
        'generated_at' => now(),
        'methodology_version' => 'test-v1',
    ]);

    $this->actingAs($user);

    $visibleIds = App\Filament\Resources\Reports\ReportResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($reportA->id)->not->toContain($reportB->id);
});

it('scopes workflow stage lists through their owning workflow while retaining generic stages', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterpriseA = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $enterpriseB = Enterprise::factory()->create(['organization_id' => $organizationB->id]);
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organizationA->id]);

    $workflowA = App\Models\Workflow::factory()->create(['enterprise_id' => $enterpriseA->id]);
    $workflowB = App\Models\Workflow::factory()->create(['enterprise_id' => $enterpriseB->id]);
    $genericWorkflow = App\Models\Workflow::factory()->generic()->create();
    $stageA = App\Models\WorkflowStage::factory()->create(['workflow_id' => $workflowA->id]);
    $stageB = App\Models\WorkflowStage::factory()->create(['workflow_id' => $workflowB->id]);
    $genericStage = App\Models\WorkflowStage::factory()->create(['workflow_id' => $genericWorkflow->id]);

    $this->actingAs($user);

    $visibleIds = App\Filament\Resources\WorkflowStages\WorkflowStageResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($stageA->id, $genericStage->id)->not->toContain($stageB->id);
});
