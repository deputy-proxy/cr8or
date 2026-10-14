<?php

use App\AI\Contracts\ModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Exceptions\WorkflowDefinitionException;
use App\Experts\MarketingExpert;
use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Services\ApprovalRequestService;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'marketing'],
        ['runtime_class' => MarketingExpert::class, 'enabled' => true],
    );
});

function workflowPublicationContext(): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'LinkedIn', 'type' => 'social']);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'marketing_strategy_id' => $strategy->getKey(),
    ]);
    $content = ContentItem::factory()->forCampaign($campaign)->create([
        'channel_id' => $channel->getKey(),
        'status' => ContentItem::STATUS_PUBLICATION_READY,
    ]);
    $account = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'channel_id' => $channel->getKey(),
        'provider' => 'linkedin',
        'name' => 'LinkedIn destination',
        'external_id' => 'linkedin-workflow-user',
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);
    $publication = Publication::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'content_item_id' => $content->getKey(),
        'channel_id' => $channel->getKey(),
        'social_account_id' => $account->getKey(),
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
    ]);
    $publication->markSubmitted('workflow-remote-post', 'https://www.linkedin.com/feed/update/workflow-remote-post')->save();
    $publication->succeed()->save();

    return [$actor, $enterprise, $publication];
}

it('executes status reconciliation from a persisted published WorkflowVersion without a ModelProvider call', function (): void {
    [$actor, $enterprise, $publication] = workflowPublicationContext();

    $provider = Mockery::mock(ModelProvider::class);
    $provider->shouldNotReceive('generate');
    app()->instance(ModelProvider::class, $provider);

    $capability = app(CapabilityRegistry::class)->resolve('publication.status.reconcile');
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'execution_policy' => ['mode' => 'interactive', 'requires_model_provider' => false],
        'completion_criteria' => [],
    ]);
    $stage = WorkflowStage::query()->create([
        'workflow_id' => $workflow->getKey(),
        'key' => 'reconcile-publication',
        'name' => 'Reconcile publication',
        'instruction' => 'Reconcile the current publication status.',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['publication.status.reconcile'],
        'capability_input_contract' => ['publication_id' => 'string|required'],
        'capability_output_contract' => $capability->outputContract,
        'input_contract' => ['required' => ['publication_id']],
        'output_contract' => ['required' => ['publication_id', 'status']],
        'repeatable' => false,
        'completion_criteria' => [
            'requires_termination_completed' => true,
            'required_output_keys' => ['publication_id', 'status'],
        ],
    ]);

    expect(fn () => app(WorkflowVersionService::class)->publish($workflow, $actor, 'invalid-contract-'.$workflow->getKey()))
        ->toThrow(WorkflowDefinitionException::class);

    $stage->forceFill(['capability_input_contract' => $capability->inputContract])->save();
    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'valid-contract-'.$workflow->getKey());

    expect($version->status)->toBe('published');

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['publication_id' => $publication->getKey()],
        'workflow-reconcile-'.$publication->getKey(),
        enterprise: $enterprise,
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and(data_get($execution->outputs, 'reconcile-publication.publication_id'))->toBe($publication->getKey())
        ->and(data_get($execution->outputs, 'reconcile-publication.status'))->toBe(Publication::STATUS_SUCCEEDED);
});
it('executes a persisted publication stage without requiring a ModelProvider or Agent approval', function (): void {
    Queue::fake();
    [$actor, $enterprise, $publication] = workflowPublicationContext();

    $provider = Mockery::mock(ModelProvider::class);
    $provider->shouldNotReceive('generate');
    app()->instance(ModelProvider::class, $provider);

    $capability = app(CapabilityRegistry::class)->resolve('publication.publish');
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'execution_policy' => ['mode' => 'interactive', 'requires_model_provider' => false],
        'completion_criteria' => [],
    ]);
    WorkflowStage::query()->create([
        'workflow_id' => $workflow->getKey(),
        'key' => 'publish-content',
        'name' => 'Publish content',
        'instruction' => 'Publish the approved content to the selected destination.',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['publication.publish'],
        'capability_input_contract' => $capability->inputContract,
        'capability_output_contract' => $capability->outputContract,
        'input_contract' => ['required' => ['content_item_id', 'social_account_id', 'scheduled_at']],
        'output_contract' => ['required' => ['id', 'status']],
        'repeatable' => false,
        'completion_criteria' => [
            'requires_termination_completed' => true,
            'required_output_keys' => ['id', 'status'],
        ],
    ]);

    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'publish-content-version-'.$workflow->getKey());
    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        [
            'content_item_id' => $publication->content_item_id,
            'social_account_id' => $publication->social_account_id,
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ],
        'workflow-publish-content-'.$publication->getKey(),
        returnFailed: true,
        enterprise: $enterprise,
    );

    $approval = ApprovalRequest::query()->where('capability', 'publication.publish')->firstOrFail();
    $approver = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $approver->getKey(), 'organization_id' => $enterprise->organization_id]);
    app(ApprovalRequestService::class)->approve($approval, $approver);
    $approval->refresh();
    $approvalTarget = json_decode((string) $approval->getRawOriginal('target_context'), true);
    if (is_string($approvalTarget)) {
        $approvalTarget = json_decode($approvalTarget, true);
    }

    expect($version->status)->toBe('published')
        ->and($execution->status)->toBe(WorkflowExecution::STATUS_WAITING_FOR_APPROVAL)
        ->and(data_get($execution->context, 'pending_approval_request_id'))->toBe($approval->getKey())
        ->and($approval->workflow_execution_id)->toBe($execution->getKey())
        ->and($approval->agent_assignment_id)->toBeNull()
        ->and(app(ApprovalRequestService::class)->matchesWorkflow($approval, $actor, $execution, 'publication.publish', $approvalTarget))->toBeTrue()
        ->and(app(ApprovalRequestService::class)->matchesWorkflow($approval, $actor, $execution, 'publication.publish', ['content_item_id' => -1]))->toBeFalse()
        ->and(app(ApprovalRequestService::class)->matchesWorkflow($approval, User::factory()->create(), $execution, 'publication.publish', $approvalTarget))->toBeFalse();

    $approvals = app(ApprovalRequestService::class);
    $approvals->consumeForWorkflow($approval, $execution);

    expect($approvals->matchesWorkflow($approval->refresh(), $actor, $execution, 'publication.publish', $approvalTarget))->toBeFalse()
        ->and($approvals->matchesWorkflow($approval->refresh(), $actor, $execution, 'publication.publish', $approvalTarget, allowConsumed: true))->toBeTrue();
});
it('requires a workflow-scoped approval before approving in-review content', function (): void {
    [$actor, $enterprise] = workflowPublicationContext();
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'marketing_strategy_id' => $strategy->getKey(),
    ]);
    $content = ContentItem::factory()->forCampaign($campaign)->create([
        'enterprise_id' => $enterprise->getKey(),
        'status' => ContentItem::STATUS_IN_REVIEW,
    ]);

    $capability = app(CapabilityRegistry::class)->resolve('marketing.content.approve');
    expect($capability->supportsExecutionMode(\App\Enums\CapabilityExecutionMode::WORKFLOW))->toBeTrue()
        ->and($capability->approvalRequirement)->toBe('required');

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'execution_policy' => ['mode' => 'interactive', 'requires_model_provider' => false],
        'completion_criteria' => [],
    ]);
    WorkflowStage::query()->create([
        'workflow_id' => $workflow->getKey(),
        'key' => 'approve_content',
        'name' => 'Approve content',
        'instruction' => 'Obtain approval for this exact content item.',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['marketing.content.approve'],
        'capability_input_contract' => $capability->inputContract,
        'capability_output_contract' => $capability->outputContract,
        'input_contract' => ['required' => ['content_item_id']],
        'output_contract' => ['required' => ['id', 'status']],
        'repeatable' => false,
        'completion_criteria' => ['requires_termination_completed' => true, 'required_output_keys' => ['id', 'status']],
    ]);

    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'approve-content-version-'.$workflow->getKey());
    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['content_item_id' => $content->getKey()],
        'workflow-approve-content-'.$content->getKey(),
        returnFailed: true,
        enterprise: $enterprise,
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_WAITING_FOR_APPROVAL)
        ->and($content->refresh()->status)->toBe(ContentItem::STATUS_IN_REVIEW);

    $approval = ApprovalRequest::query()->where('workflow_execution_id', $execution->getKey())->where('capability', 'marketing.content.approve')->firstOrFail();
    $approver = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $approver->getKey(), 'organization_id' => $enterprise->organization_id]);
    app(ApprovalRequestService::class)->approve($approval, $approver);

    $completed = app(WorkflowExecutionService::class)->continue($actor, $execution->refresh(), $execution->continuation_token, true);

    expect($completed->state_reason)->toBeNull()
        ->and($completed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($content->refresh()->status)->toBe(ContentItem::STATUS_APPROVED);
});
