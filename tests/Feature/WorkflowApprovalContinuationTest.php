<?php

use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate(['slug' => 'business-analysis'], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

it('preserves and resumes a waiting-for-approval WorkflowExecution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->id,
        'organization_id' => $enterprise->organization_id,
    ]);

    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    \App\Models\WorkflowStage::factory()->create([
        'workflow_id' => $workflow->id,
        'key' => 'research',
        'name' => 'Research',
        'sequence' => 1,
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'output_contract' => ['required' => ['analysis']],
    ]);
    app(WorkflowVersionService::class)->publish($workflow, $actor, 'approval-publish-'.$workflow->id);
    $workflow->refresh()->load('publishedVersion');

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $workflow->published_version_id,
        'workflow_version' => $workflow->publishedVersion->version,
        'enterprise_id' => $enterprise->id,
        'organization_id' => $enterprise->organization_id,
        'actor_id' => $actor->id,
        'status' => WorkflowExecution::STATUS_PENDING,
    ]);

    $execution->start()->waitForApproval('Approval required.')->save();
    $token = $execution->continuation_token;

    $completed = app(WorkflowExecutionService::class)->continue($actor, $execution, $token);

    expect($completed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($completed->state_reason)->toBeNull();
});