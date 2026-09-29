<?php

use App\Agents\Agent;
use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Events\AgentExecutionCompleted;
use App\Events\CapabilityResultReceived;
use App\Jobs\RunAgentExecutionJob;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\ExpertDescriptor;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeItem;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function phase10E2EAgentClass(): string
{
    return get_class(new class extends Agent
    {
        public function definition(): \App\Agents\AgentDefinition
        {
            return new \App\Agents\AgentDefinition(
                name: 'Phase 10 E2E Agent',
                description: 'Exercises the complete governed execution loop.',
                responsibilities: ['execute'],
                instructions: 'Use only authorized capabilities and supplied context.',
                experts: ['operations'],
                requiredContext: ['enterprise', 'knowledge', 'memory']
            );
        }
    });
}

function phase10E2EAssignment(User $actor, Enterprise $enterprise): AgentAssignment
{
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = AgentDescriptor::factory()->forRuntimeClass(phase10E2EAgentClass())->create([
        'slug' => 'phase-10-e2e-agent',
    ]);
    ExpertDescriptor::query()->updateOrCreate(['slug' => 'operations'], ['runtime_class' => \App\Experts\OperationsExpert::class, 'enabled' => true]);

    return AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
}

function phase10E2EService(FakeModelProvider $provider): AgentExecutionService
{
    return new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    );
}

it('runs assignment, Knowledge and Memory context, multi-step reasoning, governed capability, result propagation, and completion', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = phase10E2EAssignment($actor, $enterprise);
    $approver = User::factory()->create();
    Membership::factory()->admin()->create(['user_id' => $approver->id, 'organization_id' => $enterprise->organization_id]);
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $knowledgeContext = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    KnowledgeItem::factory()->create(['enterprise_id' => $enterprise->getKey(), 'knowledge_context_id' => $knowledgeContext->getKey()]);
    AgentSemanticMemory::factory()->forAgent($assignment->agentDescriptor, $enterprise)->create([
        'statement' => 'The E2E runtime should preserve governed execution history.',
        'confidence' => 0.95,
    ]);

    $calls = 0;
    $events = [];
    Event::listen('*', function (string $eventName, array $payload) use (&$events): void {
        if ($payload[0] instanceof \App\Events\AgentExecutionEvent) {
            $events[] = $payload[0]::class;
        }
    });
    $provider = new FakeModelProvider(function ($request) use (&$calls, $enterprise) {
        $calls++;
        expect($request->context)->toHaveKeys(['enterprise', 'knowledge', 'memory', 'agent', 'experts', 'target_context']);

        if ($calls === 1) {
            return new ModelResult(
                text: 'Create the item.',
                structured: [
                    'answer' => 'Create the item.',
                    'capability_requests' => [[
                        'capability' => 'work.item.create',
                        'expert_slug' => 'operations',
                        'target_context' => ['enterprise_id' => $enterprise->getKey()],
                        'input_payload' => ['name' => 'Phase 10 E2E item'],
                    ]],
                    'delegation_requests' => [],
                    'termination' => 'continue',
                    'next_step' => 'Verify the governed operation result.',
                ],
                provider: 'fake', model: 'test', invocationId: 'e2e-step-1', correlationId: $request->correlationId,
            );
        }

        expect($request->context['previous_result']['capability_results'][0]['status'])->toBe('executed');

        return new ModelResult(
            text: 'Completed from the governed result.',
            structured: [
                'answer' => 'Completed from the governed result.',
                'capability_requests' => [],
                'delegation_requests' => [],
                'termination' => 'completed',
                'termination_reason' => 'verified',
            ],
            provider: 'fake', model: 'test', invocationId: 'e2e-step-2', correlationId: $request->correlationId,
        );
    });

    $result = phase10E2EService($provider)->execute(new AgentExecutionRequest(
        actor: $actor, assignment: $assignment, prompt: 'Create and verify the work item.',
        correlationId: 'phase-10-e2e', options: ['max_steps' => 3],
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->current_step)->toBe(2)
        ->and($result->execution->steps()->count())->toBe(2)
        ->and($result->execution->last_result['capability_results'][0]['status'])->toBe('executed')
        ->and($events)->toContain(CapabilityResultReceived::class)
        ->and($events)->toContain(AgentExecutionCompleted::class);
});

it('queues an execution, retries a transient model failure, and keeps the operation governed', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = phase10E2EAssignment($actor, $enterprise);
    $calls = 0;
    $provider = new FakeModelProvider(function ($request) use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw new ModelProviderException(ModelProviderFailureType::Unavailable, 'fake', 'transient');
        }

        return new ModelResult(
            text: 'Recovered.',
            structured: ['answer' => 'Recovered.', 'capability_requests' => [], 'delegation_requests' => [], 'termination' => 'completed'],
            provider: 'fake', model: 'test', invocationId: 'async-e2e-2', correlationId: $request->correlationId,
        );
    });

    $service = phase10E2EService($provider);
    $execution = $service->queue(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Run asynchronously.', correlationId: 'async-e2e'));
    $job = new RunAgentExecutionJob($execution->getKey(), $actor->getKey());

    expect(fn () => $job->handle($service))->toThrow(ModelProviderException::class);
    expect($execution->refresh()->failure_category)->toBe('model_failed')
        ->and($execution->retry_count)->toBe(1);

    $job->handle($service);
    expect($execution->refresh()->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($calls)->toBe(2);
});

it('enforces enterprise isolation and keeps the obsolete generic mutation capability absent', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $assignment = phase10E2EAssignment($actor, $enterprise);
    $assignment->enterprise_id = $foreignEnterprise->getKey();
    $assignment->saveQuietly();

    expect(fn () => phase10E2EService(FakeModelProvider::returning())->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Cross scope.',
    )))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $paths = array_merge(
        glob(base_path('app/**/*.php')) ?: [],
        glob(base_path('tests/**/*.php')) ?: [],
        glob(base_path('docs/**/*.md')) ?: [],
        [base_path('README.md')],
    );
    $matches = [];
    foreach ($paths as $path) {
        if ($path === __FILE__) {
            continue;
        }
        if (is_file($path) && str_contains((string) file_get_contents($path), 'mcp.domain.mutation')) {
            $matches[] = $path;
        }
    }

    expect($matches)->toBe([]);
});