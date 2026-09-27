<?php

use App\Events\AgentExecutionCompleted;
use App\Events\AgentExecutionStarted;
use App\Listeners\RecordAgentExecutionEvent;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentExecutionEventRecord;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionTimelineService;

it('persists versioned execution events with correlation and scope provenance', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $descriptor = AgentDescriptor::factory()->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
    $execution = AgentExecution::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'actor_id' => $actor->getKey(),
        'correlation_id' => 'provenance-test',
    ]);

    $event = new AgentExecutionStarted(
        executionId: $execution->getKey(),
        enterpriseId: $enterprise->getKey(),
        agentAssignmentId: $assignment->getKey(),
        agentSlug: 'test-agent',
        actorId: $actor->getKey(),
        correlationId: $execution->correlation_id,
        data: ['step' => 1],
        organizationId: $enterprise->organization_id,
    );

    (new RecordAgentExecutionEvent)->handle($event);

    $record = AgentExecutionEventRecord::query()->firstOrFail();
    expect($record->event_id)->toBe($event->eventId)
        ->and($record->organization_id)->toBe($enterprise->organization_id)
        ->and($record->enterprise_id)->toBe($enterprise->getKey())
        ->and($record->agent_execution_id)->toBe($execution->getKey())
        ->and($record->version)->toBe(1)
        ->and($record->correlation_id)->toBe('provenance-test')
        ->and($record->data)->toBe(['step' => 1]);
});

it('is idempotent when an event is delivered more than once', function () {
    $enterprise = Enterprise::factory()->create();
    $execution = AgentExecution::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
    ]);
    $event = new AgentExecutionCompleted(
        executionId: $execution->getKey(),
        enterpriseId: $enterprise->getKey(),
        agentAssignmentId: $execution->agent_assignment_id,
        agentSlug: 'test-agent',
        actorId: $execution->actor_id,
        correlationId: 'duplicate-event',
        organizationId: $enterprise->organization_id,
    );
    $listener = new RecordAgentExecutionEvent;
    $listener->handle($event);
    $listener->handle($event);

    expect(AgentExecutionEventRecord::query()->where('event_id', $event->eventId)->count())->toBe(1);
});

it('returns a chronological timeline and follows delegated child executions', function () {
    $enterprise = Enterprise::factory()->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
    ]);
    $child = AgentExecution::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
    ]);

    $execution->delegationsFrom()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'source_agent_assignment_id' => $assignment->getKey(),
        'target_agent_assignment_id' => $assignment->getKey(),
        'parent_agent_execution_id' => $execution->getKey(),
        'target_agent_execution_id' => $child->getKey(),
        'actor_id' => User::factory()->create()->getKey(),
        'organization_name' => 'Org',
        'enterprise_name' => $enterprise->name,
        'source_agent_slug' => 'source',
        'source_agent_runtime_class' => AgentDescriptor::class,
        'target_agent_slug' => 'target',
        'target_agent_runtime_class' => AgentDescriptor::class,
        'actor_name' => 'System',
        'capability' => 'test',
        'prompt' => 'delegate',
        'target_context' => [],
        'correlation_id' => 'graph',
        'idempotency_key' => 'graph-1',
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    foreach ([[$execution, 2], [$child, 1]] as [$item, $step]) {
        (new RecordAgentExecutionEvent)->handle(new AgentExecutionStarted(
            executionId: $item->getKey(),
            enterpriseId: $enterprise->getKey(),
            agentAssignmentId: $assignment->getKey(),
            agentSlug: 'test',
            actorId: null,
            correlationId: 'graph',
            data: ['step' => $step],
            organizationId: $enterprise->organization_id,
        ));
    }

    $timeline = app(AgentExecutionTimelineService::class);
    expect($timeline->forExecution($execution))->toHaveCount(1)
        ->and($timeline->graph($execution))->toHaveCount(2)
        ->and($timeline->graph($execution)->pluck('agent_execution_id')->unique()->sort()->values()->all())
        ->toBe([$execution->getKey(), $child->getKey()]);
});