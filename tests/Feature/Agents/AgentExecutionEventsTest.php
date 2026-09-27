<?php

use App\Agents\Agent;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Events\AgentExecutionCompleted;
use App\Events\AgentExecutionStarted;
use App\Events\AgentReasoningCompleted;
use App\Events\CapabilityAuthorized;
use App\Events\CapabilityRequested;
use App\Events\CapabilityResultReceived;
use App\Events\KnowledgeRetrieved;
use App\Events\OperationExecuted;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Support\Facades\Event;

function eventAgentClass(): string
{
    return get_class(new class extends Agent
    {
        public function definition(): \App\Agents\AgentDefinition
        {
            return new \App\Agents\AgentDefinition(
                name: 'Event Test Agent',
                description: 'Exercises execution event emission.',
                responsibilities: ['execute'],
                instructions: 'Execute through governed boundaries.',
                experts: [],
                requiredContext: ['enterprise', 'knowledge'],
                capabilities: ['work.item.create'],
            );
        }
    });
}

function eventAgentAssignment(User $actor, Enterprise $enterprise): AgentAssignment
{
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(eventAgentClass())->create([
        'slug' => 'event-test-agent',
    ]);

    return AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
}

it('emits an ordered lifecycle event stream with reconstructable identifiers', function () {
    $seen = [];
    Event::listen('*', function (string $eventName, array $payload) use (&$seen): void {
        if ($payload[0] instanceof \App\Events\AgentExecutionEvent) {
            $seen[] = $payload[0];
        }
    });

    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = eventAgentAssignment($actor, $enterprise);
    $provider = new FakeModelProvider(fn ($request) => new ModelResult(
        text: 'Completed.',
        structured: [
            'answer' => 'Completed.',
            'capability_requests' => [],
            'delegation_requests' => [],
            'termination' => 'completed',
        ],
        provider: 'fake',
        model: 'test',
        invocationId: 'event-lifecycle-1',
        correlationId: $request->correlationId,
    ));

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Emit lifecycle events.',
        correlationId: 'event-lifecycle',
    ));

    $classes = array_map(static fn ($event): string => $event::class, $seen);
    $started = array_search(AgentExecutionStarted::class, $classes, true);
    $knowledge = array_search(KnowledgeRetrieved::class, $classes, true);
    $reasoning = array_search(AgentReasoningCompleted::class, $classes, true);
    $completed = array_search(AgentExecutionCompleted::class, $classes, true);

    expect($result->execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($started)->toBeInt()
        ->and($knowledge)->toBeInt()
        ->and($reasoning)->toBeInt()
        ->and($completed)->toBeInt()
        ->and($started)->toBeLessThan($reasoning)
        ->and($knowledge)->toBeLessThan($reasoning)
        ->and($reasoning)->toBeLessThan($completed);

    $lifecycleEvent = $seen[$started];
    expect($lifecycleEvent->executionId)->toBe($result->execution->getKey())
        ->and($lifecycleEvent->enterpriseId)->toBe($enterprise->getKey())
        ->and($lifecycleEvent->agentAssignmentId)->toBe($assignment->getKey())
        ->and($lifecycleEvent->actorId)->toBe($actor->getKey())
        ->and($lifecycleEvent->correlationId)->toBe('event-lifecycle');
});

it('emits governed capability and operation events without exposing model output', function () {
    $seen = [];
    Event::listen('*', function (string $eventName, array $payload) use (&$seen): void {
        if ($payload[0] instanceof \App\Events\AgentExecutionEvent) {
            $seen[] = $payload[0];
        }
    });

    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = eventAgentAssignment($actor, $enterprise);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

    $provider = new FakeModelProvider(fn ($request) => new ModelResult(
        text: 'Secret internal reasoning that must not enter lifecycle events.',
        structured: [
            'answer' => 'Safe answer.',
            'capability_requests' => [[
                'capability' => 'work.item.create',
                'target_context' => ['enterprise_id' => $enterprise->getKey()],
                'input_payload' => ['name' => 'Event work item'],
            ]],
            'delegation_requests' => [],
            'termination' => 'completed',
        ],
        provider: 'fake',
        model: 'test',
        invocationId: 'event-capability-1',
        correlationId: $request->correlationId,
    ));

    (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Execute a governed capability.',
        correlationId: 'event-capability',
    ));

    $classes = array_map(static fn ($event): string => $event::class, $seen);
    expect($classes)->toContain(CapabilityRequested::class)
        ->toContain(CapabilityAuthorized::class)
        ->toContain(OperationExecuted::class)
        ->toContain(CapabilityResultReceived::class);

    foreach ($seen as $event) {
        expect(json_encode($event->toArray()))->not->toContain('Secret internal reasoning');
    }
});

it('sanitizes dangerous event fields and keeps events versioned and immutable', function () {
    $event = new AgentExecutionStarted(
        executionId: 10,
        enterpriseId: 20,
        agentAssignmentId: 30,
        agentSlug: 'event-agent',
        actorId: 40,
        correlationId: 'event-contract',
        provenance: [
            'operation' => 'work.item.create',
            'reasoning' => 'must not leak',
        ],
        data: [
            'safe' => 'value',
            'prompt' => 'must not leak',
            'nested' => ['analysis' => 'must not leak', 'count' => 2],
        ],
    );

    $payload = $event->toArray();

    expect($event::VERSION)->toBe(1)
        ->and($event::VISIBILITY)->toBe('internal')
        ->and($payload['execution_id'])->toBe(10)
        ->and($payload['provenance'])->toBe(['operation' => 'work.item.create'])
        ->and($payload['data'])->toBe(['safe' => 'value', 'nested' => ['count' => 2]])
        ->and(json_encode($payload))->not->toContain('must not leak');
});