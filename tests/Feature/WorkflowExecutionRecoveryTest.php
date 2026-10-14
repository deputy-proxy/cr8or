<?php

use App\Capabilities\CapabilityRegistry;
use App\Events\ContentItemApproved;
use App\Experts\CopywritingExpert;
use App\Experts\MarketingExpert;
use App\Jobs\ProcessWorkflowContinuationOutbox;
use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowContinuationOutbox;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Services\ApprovalRequestService;
use App\Services\ContentItemService;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);

    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'marketing'],
        ['runtime_class' => MarketingExpert::class, 'enabled' => true],
    );
    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'copywriting'],
        ['runtime_class' => CopywritingExpert::class, 'enabled' => true],
    );
});

/** @return array{User, User, Enterprise, Campaign, Channel, WorkflowExecution} */
function startContentApprovalWorkflow(): array
{
    Queue::fake();

    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $approver = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->getKey(), 'organization_id' => $organization->getKey()]);
    Membership::factory()->owner()->create(['user_id' => $approver->getKey(), 'organization_id' => $organization->getKey()]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Facebook', 'type' => 'Facebook']);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->getKey(), 'marketing_strategy_id' => $strategy->getKey()]);

    $registry = app(CapabilityRegistry::class);
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'execution_policy' => ['mode' => 'interactive', 'requires_model_provider' => false],
        'completion_criteria' => ['required_stage_keys' => ['create_content', 'submit_for_review', 'approve_content']],
    ]);

    $definitions = [
        [
            'key' => 'create_content', 'name' => 'Create content', 'sequence' => 1, 'dependencies' => [],
            'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.content.create'],
            'input_contract' => ['required' => ['campaign_id', 'channel_id', 'title', 'body']],
            'output_contract' => ['required' => ['id']],
            'completion_criteria' => ['requires_termination_completed' => true, 'required_output_keys' => ['id']],
        ],
        [
            'key' => 'submit_for_review', 'name' => 'Submit for review', 'sequence' => 2, 'dependencies' => ['create_content'],
            'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.content.review'],
            'input_contract' => ['mappings' => ['content_item_id' => 'stages.create_content.id']],
            'output_contract' => ['required' => ['success']],
            'completion_criteria' => ['requires_termination_completed' => true, 'required_output_keys' => ['success']],
        ],
        [
            'key' => 'approve_content', 'name' => 'Approve content', 'sequence' => 3, 'dependencies' => ['create_content', 'submit_for_review'],
            'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.content.approve'],
            'input_contract' => [
                'required' => ['approval_request_id'],
                'requested' => ['approval_request_id'],
                'mappings' => ['content_item_id' => 'stages.create_content.id'],
            ],
            'output_contract' => ['required' => ['success']],
            'completion_criteria' => ['requires_termination_completed' => true, 'required_output_keys' => ['success']],
        ],
    ];

    foreach ($definitions as $definition) {
        $capability = $registry->resolve($definition['capability_slugs'][0]);
        WorkflowStage::query()->create([
            'workflow_id' => $workflow->getKey(),
            ...$definition,
            'instruction' => 'Run this governed content lifecycle stage.',
            'capability_input_contract' => $capability->inputContract,
            'capability_output_contract' => $capability->outputContract,
            'repeatable' => false,
        ]);
    }

    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'content-approval-workflow-'.$workflow->getKey());
    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['workflow' => ['enterprise_id' => $enterprise->getKey()], 'stages' => ['create_content' => [
            'campaign_id' => $campaign->getKey(),
            'channel_id' => $channel->getKey(),
            'title' => 'A practical online business checklist',
            'body' => 'Validate the problem before investing in the solution.',
        ]]],
        'content-approval-execution-'.$workflow->getKey(),
        returnFailed: true,
        enterprise: $enterprise,
    );

    return [$actor, $approver, $enterprise, $campaign, $channel, $execution];
}

it('adapts operation domain results to the declared capability output contract', function (): void {
    [$actor, , , , , $execution] = startContentApprovalWorkflow();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_WAITING_FOR_APPROVAL)
        ->and(data_get($execution->outputs, 'submit_for_review.success'))->toBeTrue()
        ->and(data_get($execution->outputs, 'submit_for_review.id'))->not->toBeNull()
        ->and(data_get($execution->outputs, 'submit_for_review.result.id'))->toBe(data_get($execution->outputs, 'submit_for_review.id'));
});

it('automatically resumes a waiting Workflow after its exact approval request is granted', function (): void {
    [$actor, $approver, , , , $execution] = startContentApprovalWorkflow();
    $approval = ApprovalRequest::query()
        ->where('workflow_execution_id', $execution->getKey())
        ->where('capability', 'marketing.content.approve')
        ->firstOrFail();

    $contentId = (int) data_get($execution->outputs, 'create_content.id');
    app(ApprovalRequestService::class)->approve($approval, $approver, 'Approved for publication workflow.');
    $outbox = WorkflowContinuationOutbox::query()->where('event_type', 'workflow.approval.granted')->firstOrFail();
    expect(data_get($approval->refresh()->target_context, 'content_item_id'))->toBe($contentId);
    $this->artisan('workflow:dispatch-pending-continuations')->assertExitCode(0);
    Queue::assertPushed(ProcessWorkflowContinuationOutbox::class, fn (ProcessWorkflowContinuationOutbox $job): bool => $job->outboxId === (int) $outbox->getKey());

    (new ProcessWorkflowContinuationOutbox((int) $outbox->getKey()))->handle(app(WorkflowExecutionService::class));
    (new ProcessWorkflowContinuationOutbox((int) $outbox->getKey()))->handle(app(WorkflowExecutionService::class));

    expect($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and(ContentItem::query()->where('enterprise_id', $execution->enterprise_id)->firstOrFail()->status)->toBe(ContentItem::STATUS_APPROVED)
        ->and($outbox->refresh()->processed_at)->not->toBeNull()
        ->and(WorkflowContinuationOutbox::query()->where('event_type', ContentItemApproved::EVENT_TYPE)->exists())->toBeTrue();
});

it('reconciles approved content against a failed lifecycle stage without repeating transitions', function (): void {
    [$actor, $approver, , , , $execution] = startContentApprovalWorkflow();
    $approval = ApprovalRequest::query()
        ->where('workflow_execution_id', $execution->getKey())
        ->where('capability', 'marketing.content.approve')
        ->firstOrFail();
    $content = ContentItem::query()->findOrFail((int) data_get($execution->outputs, 'create_content.id'));

    $execution->forceFill([
        'status' => WorkflowExecution::STATUS_FAILED,
        'current_stage_key' => 'approve_content',
        'failure_reason' => 'Simulated output-contract failure after review.',
        'completed_at' => now(),
        'context' => [...($execution->context ?? []), 'pending_approval_request_id' => $approval->getKey()],
    ])->save();

    app(ContentItemService::class)->approve($approver, $content);
    $outbox = WorkflowContinuationOutbox::query()->where('event_type', ContentItemApproved::EVENT_TYPE)->firstOrFail();

    (new ProcessWorkflowContinuationOutbox((int) $outbox->getKey()))->handle(app(WorkflowExecutionService::class));

    expect($content->refresh()->status)->toBe(ContentItem::STATUS_APPROVED)
        ->and($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->outputs['approve_content']['reconciled'])->toBeTrue()
        ->and($approval->refresh()->status)->toBe(ApprovalRequest::STATUS_CANCELLED)
        ->and($outbox->refresh()->processed_at)->not->toBeNull();
});

it('does not reconcile approved content for a different content target', function (): void {
    [$actor, , $enterprise, $campaign, $channel, $execution] = startContentApprovalWorkflow();
    $otherContent = ContentItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'campaign_id' => $campaign->getKey(),
        'channel_id' => $channel->getKey(),
        'status' => ContentItem::STATUS_APPROVED,
    ]);
    $beforeOutputs = $execution->outputs;

    expect(app(WorkflowExecutionService::class)->continueAfterContentApproval($otherContent, $actor->getKey()))->toBeTrue()
        ->and($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_WAITING_FOR_APPROVAL)
        ->and($execution->outputs)->toBe($beforeOutputs);
});
