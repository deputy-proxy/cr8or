<?php

use App\Agents\Agent;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecutionStep;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use App\Services\WorkflowVersionService;

function optionalWorkflowAgentRuntimeClass(): string
{
    return get_class(new class extends Agent
    {
        public function definition(): \App\Agents\AgentDefinition
        {
            return new \App\Agents\AgentDefinition(
                name: 'Workflow Planner',
                description: 'Plans around governed Workflows.',
                responsibilities: ['select', 'inspect', 'adapt'],
                instructions: 'Select governed Workflows and inspect their persisted results.',
                experts: [],
                requiredContext: ['enterprise'],
            );
        }
    });
}

function optionalWorkflowAssignment(User $actor, Enterprise $enterprise): AgentAssignment
{
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = AgentDescriptor::factory()
        ->forRuntimeClass(optionalWorkflowAgentRuntimeClass())
        ->create(['slug' => 'workflow-planner']);

    return AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
}

function deterministicTestWorkflow(Enterprise $enterprise, array $capabilities): Workflow
{
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Deterministic test workflow',
        'purpose' => 'Prove Agent-to-Workflow orchestration.',
        'execution_policy' => [
            'template' => 'test.deterministic',
            'mode' => 'deterministic',
            'requires_model_provider' => false,
        ],
    ]);

    $workflow->stages()->create([
        'key' => 'execute',
        'name' => 'Execute deterministic operation',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['marketing'],
        'capability_slugs' => $capabilities,
        'input_contract' => [
            'required' => ['section_key'],
            'defaults' => ['section_key' => 'enterprise_context'],
        ],
        'output_contract' => ['required' => []],
        'completion_criteria' => ['requires_termination_completed' => true],
        'repeatable' => false,
    ]);

    return $workflow->load('stages');
}

it('lets an Agent wrap a deterministic Workflow without making the Workflow depend on AgentExecution', function (): void {
    $this->seed([\Database\Seeders\ExpertDescriptorSeeder::class]);

    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = optionalWorkflowAssignment($actor, $enterprise);
    $workflow = deterministicTestWorkflow($enterprise, ['marketing.strategy.section.define']);

    app(WorkflowVersionService::class)->publish($workflow, $actor, 'deterministic-v1');

    $calls = 0;
    $provider = new FakeModelProvider(function ($request) use (&$calls): ModelResult {
        $calls++;

        expect($request->context['previous_result']['workflow_execution']['status'])->toBe(\App\Models\WorkflowExecution::STATUS_COMPLETED);

        return new ModelResult(
            text: 'The Workflow result is complete.',
            structured: [
                'answer' => 'The Workflow result is complete.',
                'decision_title' => 'Workflow reviewed',
                'decision_summary' => 'The Agent inspected the deterministic Workflow result.',
                'decision_rationale' => 'The Workflow completed without requiring Agent orchestration.',
                'capability_requests' => [],
                'delegation_requests' => [],
                'termination' => 'completed',
                'termination_reason' => 'workflow_result_reviewed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'workflow-review',
            correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Run the selected deterministic Workflow and review its result.',
        workflow: $workflow->refresh(),
        correlationId: 'agent-workflow-wrapper',
    ));

    $workflowExecutionId = $result->execution->execution_context['workflow_execution_id'];
    $workflowExecution = \App\Models\WorkflowExecution::query()->findOrFail($workflowExecutionId);

    expect($calls)->toBe(1)
        ->and($result->succeeded())->toBeTrue()
        ->and($workflowExecution->status)->toBe(\App\Models\WorkflowExecution::STATUS_COMPLETED)
        ->and($result->execution->steps()->pluck('type')->all())->toBe([
            AgentExecutionStep::TYPE_WORKFLOW,
            AgentExecutionStep::TYPE_REASONING,
        ])
        ->and($result->execution->steps()->first()->workflow_stage_id)->toBeNull()
        ->and($result->execution->last_result['workflow_execution']['workflow_execution_id'])->toBe($workflowExecution->getKey());
});

it('lets an Agent inspect a failed deterministic Workflow and adapt without hiding the Workflow failure', function (): void {
    $this->seed([\Database\Seeders\ExpertDescriptorSeeder::class]);

    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = optionalWorkflowAssignment($actor, $enterprise);
    $workflow = deterministicTestWorkflow($enterprise, ['capability.does.not.exist']);

    app(WorkflowVersionService::class)->publish($workflow, $actor, 'deterministic-failure-v1');

    $provider = new FakeModelProvider(function ($request): ModelResult {
        expect($request->context['previous_result']['workflow_execution']['status'])->toBe(\App\Models\WorkflowExecution::STATUS_FAILED);

        return new ModelResult(
            text: 'The Workflow failed, so I will stop safely.',
            structured: [
                'answer' => 'The Workflow failed, so I will stop safely.',
                'decision_title' => 'Workflow failure reviewed',
                'decision_summary' => 'The Agent preserved the Workflow failure and chose not to hide it.',
                'decision_rationale' => 'Recovery remains a governed planning decision.',
                'capability_requests' => [],
                'delegation_requests' => [],
                'termination' => 'completed',
                'termination_reason' => 'workflow_failure_reviewed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'workflow-failure-review',
            correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Run the selected Workflow and adapt if it fails.',
        workflow: $workflow->refresh(),
        correlationId: 'agent-workflow-failure',
    ));

    $workflowExecution = \App\Models\WorkflowExecution::query()
        ->where('workflow_id', $workflow->getKey())
        ->firstOrFail();

    expect($result->succeeded())->toBeTrue()
        ->and($workflowExecution->status)->toBe(\App\Models\WorkflowExecution::STATUS_FAILED)
        ->and($workflowExecution->failure_reason)->not->toBeNull()
        ->and($result->execution->last_result['workflow_execution']['status'])->toBe(\App\Models\WorkflowExecution::STATUS_FAILED)
        ->and($result->execution->steps()->pluck('type')->all())->toBe([
            AgentExecutionStep::TYPE_WORKFLOW,
            AgentExecutionStep::TYPE_REASONING,
        ]);
});