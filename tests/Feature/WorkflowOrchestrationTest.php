<?php

use App\Models\Enterprise;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkItem;
use LogicException;

it('persists an ordered workflow stage graph with governed contracts', function () {
    $enterprise = Enterprise::factory()->create();
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);

    $first = WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => 'research',
        'sequence' => 1,
        'expert_slugs' => ['marketing-research'],
        'capability_slugs' => ['knowledge.search'],
        'input_contract' => ['required' => ['enterprise_context']],
        'output_contract' => ['required' => ['research_summary']],
    ]);
    $second = WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => 'strategy',
        'sequence' => 2,
        'dependencies' => ['research'],
        'expert_slugs' => ['marketing-strategy'],
        'capability_slugs' => ['strategy.create'],
    ]);

    expect($workflow->stages()->pluck('key')->all())->toBe(['research', 'strategy'])
        ->and($first->expert_slugs)->toBe(['marketing-research'])
        ->and($second->dependencies)->toBe(['research']);

    expect(fn () => $second->assertDependenciesSatisfied([]))
        ->toThrow(LogicException::class, 'cannot execute before dependency [research] is completed');

    $second->assertDependenciesSatisfied(['research']);
    expect(true)->toBeTrue();
});

it('associates a workflow with a WorkItem inside the same enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise]);
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise,
        'work_item_id' => $workItem,
    ]);

    expect($workflow->workItem->is($workItem))->toBeTrue()
        ->and($workItem->workflow->is($workflow))->toBeTrue()
        ->and($workflow->version)->toBe(1);
});

it('evaluates deterministic Workflow completion from WorkflowExecution state', function () {
    $enterprise = Enterprise::factory()->create();
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    WorkflowStage::factory()->create(['workflow_id' => $workflow, 'key' => 'research', 'sequence' => 1]);
    WorkflowStage::factory()->create(['workflow_id' => $workflow, 'key' => 'strategy', 'sequence' => 2, 'dependencies' => ['research']]);

    $version = \App\Models\WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => \App\Models\WorkflowVersion::STATUS_PUBLISHED,
    ]);
    $execution = \App\Models\WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise,
        'organization_id' => $enterprise->organization_id,
        'outputs' => ['research' => ['termination' => 'completed']],
    ]);

    expect($workflow->completionSatisfied($execution))->toBeFalse();

    $execution->setAttribute('outputs', [
        'research' => ['termination' => 'completed'],
        'strategy' => ['termination' => 'completed'],
    ])->save();

    expect($workflow->completionSatisfied($execution))->toBeTrue();
});

it('does not consider a workflow complete until every stage has completed', function () {
    $enterprise = Enterprise::factory()->create();
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $first = WorkflowStage::factory()->create(['workflow_id' => $workflow, 'key' => 'research', 'sequence' => 1]);
    $second = WorkflowStage::factory()->create(['workflow_id' => $workflow, 'key' => 'strategy', 'sequence' => 2, 'dependencies' => ['research']]);

    $execution = \App\Models\AgentExecution::factory()->forEnterprise($enterprise)->create([
        'workflow_id' => $workflow,
        'workflow_version' => $workflow->version,
    ]);

    $stepData = [
        'organization_id' => $execution->organization_id,
        'enterprise_id' => $execution->enterprise_id,
        'agent_execution_id' => $execution->getKey(),
        'workflow_stage_id' => $first->getKey(),
        'sequence' => 1,
        'status' => \App\Models\AgentExecutionStep::STATUS_COMPLETED,
        'type' => \App\Models\AgentExecutionStep::TYPE_WORKFLOW,
        'output' => ['termination' => 'completed'],
        'capability_requests' => [],
        'correlation_id' => 'workflow-test',
        'idempotency_key' => 'workflow-test-step-1',
    ];
    \App\Models\AgentExecutionStep::create($stepData);

    expect($workflow->completionSatisfied($execution))->toBeFalse();

    \App\Models\AgentExecutionStep::create([
        ...$stepData,
        'workflow_stage_id' => $second->getKey(),
        'sequence' => 2,
        'idempotency_key' => 'workflow-test-step-2',
    ]);

    expect($workflow->completionSatisfied($execution))->toBeTrue();
});
