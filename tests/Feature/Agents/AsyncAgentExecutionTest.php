<?php

use App\Agents\Agent;
use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Jobs\RunAgentExecutionJob;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentExecutionStep;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Support\Facades\Queue;

function asyncAgentClass(): string
{
    return get_class(new class extends Agent
    {
        public function definition(): \App\Agents\AgentDefinition
        {
            return new \App\Agents\AgentDefinition(
                name: 'Async Test Agent',
                description: 'Exercises durable queued execution.',
                responsibilities: ['execute'],
                instructions: 'Execute only through governed capabilities.',
                experts: [],
                requiredContext: ['enterprise'],
                capabilities: ['work.item.create'],
            );
        }
    });
}

function asyncAgentAssignment(User $actor, Enterprise $enterprise): AgentAssignment
{
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = AgentDescriptor::factory()->forRuntimeClass(asyncAgentClass())->create([
        'slug' => 'async-test-agent',
    ]);

    return AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
}

function asyncAgentService(FakeModelProvider $provider): AgentExecutionService
{
    return new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    );
}

it('persists a requested execution before dispatching it to the Agent queue', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = asyncAgentAssignment($actor, $enterprise);

    $provider = new FakeModelProvider(fn ($request) => new ModelResult(
        text: 'Completed asynchronously.',
        structured: [
            'answer' => 'Completed asynchronously.',
            'capability_requests' => [],
            'delegation_requests' => [],
            'termination' => 'completed',
            'termination_reason' => 'async_complete',
        ],
        provider: 'fake',
        model: 'test',
        invocationId: 'async-1',
        correlationId: $request->correlationId,
    ));

    $execution = asyncAgentService($provider)->queue(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Run asynchronously.',
        correlationId: 'async-dispatch',
    ));

    expect($execution->status)->toBe(AgentExecution::STATUS_REQUESTED)
        ->and($execution->exists)->toBeTrue();

    Queue::assertPushed(RunAgentExecutionJob::class, fn (RunAgentExecutionJob $job): bool => $job->executionId === $execution->getKey());
});

it('retries a retryable worker failure from durable execution state without failing the execution', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = asyncAgentAssignment($actor, $enterprise);
    $calls = 0;

    $provider = new FakeModelProvider(function ($request) use (&$calls) {
        $calls++;

        if ($calls === 1) {
            throw new ModelProviderException(ModelProviderFailureType::Unavailable, 'fake', 'provider unavailable');
        }

        return new ModelResult(
            text: 'Recovered.',
            structured: [
                'answer' => 'Recovered.',
                'capability_requests' => [],
                'delegation_requests' => [],
                'termination' => 'completed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'retry-2',
            correlationId: $request->correlationId,
        );
    });

    $service = asyncAgentService($provider);
    $execution = $service->queue(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Retry me.',
        correlationId: 'retryable-worker',
    ));
    $job = new RunAgentExecutionJob($execution->getKey(), $actor->getKey());

    expect(fn () => $job->handle($service))->toThrow(ModelProviderException::class);

    expect($execution->refresh()->status)->toBe(AgentExecution::STATUS_EXECUTING)
        ->and($execution->failure_code)->toBe('provider.unavailable')
        ->and($execution->steps()->firstOrFail()->status)->toBe(AgentExecutionStep::STATUS_RUNNING);

    $job->handle($service);

    expect($calls)->toBe(2)
        ->and($execution->refresh()->status)->toBe(AgentExecution::STATUS_COMPLETED);
});

it('reuses persisted capability results when a worker retries a running step', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = asyncAgentAssignment($actor, $enterprise);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

    $providerCalls = 0;
    $provider = new FakeModelProvider(function ($request) use (&$providerCalls, $enterprise) {
        $providerCalls++;

        return new ModelResult(
            text: 'Should not be called after persisted model state.',
            structured: [
                'answer' => 'Should not be called.',
                'capability_requests' => [[
                    'capability' => 'work.item.create',
                    'target_context' => ['enterprise_id' => $enterprise->getKey()],
                    'input_payload' => ['name' => 'Duplicate guard'],
                ]],
                'delegation_requests' => [],
                'termination' => 'completed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'unexpected-provider-call',
            correlationId: $request->correlationId,
        );
    });

    $service = asyncAgentService($provider);
    $execution = $service->queue(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Resume persisted step.',
        correlationId: 'persisted-step-retry',
    ));
    $idempotencyKey = hash('sha256', implode('|', [$execution->idempotency_key, 1, 0, 'work.item.create']));

    $modelResult = [
        'text' => 'Persisted model result.',
        'structured' => [
            'answer' => 'Persisted model result.',
            'capability_requests' => [[
                'capability' => 'work.item.create',
                'target_context' => ['enterprise_id' => $enterprise->getKey()],
                'input_payload' => ['name' => 'Duplicate guard'],
            ]],
            'delegation_requests' => [],
            'termination' => 'completed',
        ],
        'provider' => 'fake',
        'model' => 'test',
        'invocation_id' => 'persisted-model',
        'usage' => [],
        'correlation_id' => $execution->correlation_id,
    ];
    $cachedResult = [
        'status' => 'executed',
        'capability' => 'work.item.create',
        'idempotency_key' => $idempotencyKey,
        'result' => ['id' => 999, 'name' => 'Already executed'],
        'provenance' => ['operation' => \App\Operations\CreateWorkItem::class],
    ];

    $step = AgentExecutionStep::query()->create([
        'organization_id' => $execution->organization_id,
        'enterprise_id' => $execution->enterprise_id,
        'agent_execution_id' => $execution->getKey(),
        'sequence' => 1,
        'status' => AgentExecutionStep::STATUS_RUNNING,
        'type' => AgentExecutionStep::TYPE_REASONING,
        'intent' => 'Resume persisted step.',
        'output' => [
            'model_result' => $modelResult,
            'capability_results' => [$cachedResult],
            'delegation_results' => [],
        ],
        'idempotency_key' => $execution->idempotency_key.':1',
    ]);
    $execution->current_step = 0;
    $execution->status = AgentExecution::STATUS_EXECUTING;
    $execution->save();

    $job = new RunAgentExecutionJob($execution->getKey(), $actor->getKey());
    $job->handle($service);

    expect($providerCalls)->toBe(0)
        ->and($step->refresh()->status)->toBe(AgentExecutionStep::STATUS_COMPLETED)
        ->and($execution->refresh()->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($execution->last_result['capability_results'][0]['idempotency_key'])->toBe($idempotencyKey);
});

it('cancels queued executions durably and marks timed-out workers explicitly', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = asyncAgentAssignment($actor, $enterprise);
    $calls = 0;
    $provider = new FakeModelProvider(function () use (&$calls) {
        $calls++;

        return new ModelResult(
            text: 'Should not run.',
            structured: ['answer' => 'Should not run.', 'capability_requests' => [], 'delegation_requests' => [], 'termination' => 'completed'],
            provider: 'fake',
            model: 'test',
            invocationId: 'cancelled',
        );
    });

    $service = asyncAgentService($provider);
    $execution = $service->queue(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Cancel me.',
        correlationId: 'cancelled-execution',
    ));
    $service->cancel($execution, $actor, 'Cancelled by test.');

    $job = new RunAgentExecutionJob($execution->getKey(), $actor->getKey());
    $job->handle($service);

    expect($calls)->toBe(0)
        ->and($execution->refresh()->status)->toBe(AgentExecution::STATUS_CANCELLED);

    $timedOut = $service->queue(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Timeout me.',
        correlationId: 'timeout-execution',
    ));
    (new RunAgentExecutionJob($timedOut->getKey(), $actor->getKey()))->failed(new RuntimeException('Worker timeout.'));

    expect($timedOut->refresh()->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and($timedOut->failure_code)->toBe('timeout');
});