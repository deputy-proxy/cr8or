<?php

use App\Agents\Agent;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Data\CapabilityRequest;
use App\Experts\Expert;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentExecution;
use App\Models\AgentExecutionStep;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

function testAgentRuntimeClass(): string
{
    return get_class(new class extends Agent
    {
        public function definition(): \App\Agents\AgentDefinition
        {
            return new \App\Agents\AgentDefinition(name: 'Planner', description: 'Plans governed enterprise work.', responsibilities: ['plan'], instructions: 'Plan governed enterprise work within the supplied authorized context.', experts: ['analyst'], requiredContext: ['enterprise', 'knowledge', 'strategy', 'work']);
        }
    });
}

function testExpertRuntimeClass(): string
{
    return get_class(new class extends Expert
    {
        public function definition(): \App\Experts\ExpertDefinition
        {
            return new \App\Experts\ExpertDefinition(
                name: 'Analyst',
                description: 'Analyzes enterprise context.',
                responsibilities: ['analyze'],
                methodology: 'Evidence-first analysis.',
                requiredContext: ['enterprise'],
                capabilities: ['work.item.create'],
            );
        }

        public function analyze(array $context): array
        {
            return ['enterprise' => $context['enterprise']['enterprise']['slug']];
        }
    });
}

function governedAssignment(User $actor, ?Enterprise $enterprise = null): AgentAssignment
{
    $enterprise ??= Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass(testAgentRuntimeClass())
        ->create(['slug' => 'planner']);

    return AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
}

it('executes an authorized Agent with only the requested enterprise domain context', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    $assignment = governedAssignment($actor, $enterprise);

    \App\Models\EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'description' => 'Authorized context',
    ]);

    $provider = new FakeModelProvider(function ($request) {
        expect($request->context)->toHaveKeys(['enterprise', 'knowledge', 'strategy', 'work', 'agent', 'experts', 'target_context']);

        return new \App\AI\Data\ModelResult(
            text: 'Plan complete.',
            structured: [
                'answer' => 'Plan complete.',
                'decision_title' => 'Proceed',
                'decision_summary' => 'The authorized context supports proceeding.',
                'decision_rationale' => 'Reviewed the available enterprise context.',
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'fake-1',
            correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Create a plan.', correlationId: 'agent-test-123'));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(AgentExecution::STATUS_SUCCEEDED)
        ->and($result->decision)->toBeInstanceOf(AgentDecision::class)
        ->and($result->decision->execution_id)->toBe($result->execution->getKey())
        ->and($result->execution->correlation_id)->toBe('agent-test-123')
        ->and($result->execution->provider)->toBe('fake')
        ->and($result->execution->external_execution_id)->toBe('fake-1');
});

it('denies disabled Agents and does not invoke the provider', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);
    $assignment->update(['enabled' => false]);

    $provider = new FakeModelProvider(fn () => throw new RuntimeException('Provider must not be called.'));

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Run.')))
        ->toThrow(AuthorizationException::class, 'Agent assignment is disabled.');
});

it('denies cross-organization Agent execution', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    $otherEnterprise = Enterprise::factory()->create();
    $assignment->enterprise_id = $otherEnterprise->getKey();
    $assignment->saveQuietly();

    $provider = new FakeModelProvider(fn () => throw new RuntimeException('Provider must not be called.'));

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Run.')))
        ->toThrow(AuthorizationException::class);
});

it('coordinates only enabled Experts and gives them the Agent context without extra authority', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    $expert = ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);

    $provider = new FakeModelProvider(function ($request) {
        expect($request->context['experts']['results'][0]['expert'])->toBe('Analyst')
            ->and($request->context['experts']['results'][0]['result'])->toMatchArray([
                'enterprise' => $request->context['enterprise']['enterprise']['slug'],
            ])
            ->and($request->context['instructions']['agent']['instructions'])->toContain('supplied authorized context')
            ->and($request->context['instructions']['experts'][0]['methodology'])->toBe('Evidence-first analysis.');

        return new \App\AI\Data\ModelResult(
            text: 'Analyzed.',
            structured: [
                'answer' => 'Analyzed.',
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'fake-expert',
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Analyze.', expertSlugs: [$expert->slug]));

    expect($result->succeeded())->toBeTrue();
});

it('denies an Expert that is not declared by the Agent assignment', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    $expert = ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'unauthorized-analyst']);

    $provider = new FakeModelProvider(fn () => throw new RuntimeException('Provider must not be called.'));

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Analyze.', expertSlugs: [$expert->slug])))
        ->toThrow(AuthorizationException::class, 'Expert [unauthorized-analyst] is not declared by Agent [Planner].');
});

it('re-authorizes a state-changing capability and requires approval when configured', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);

    $provider = new FakeModelProvider(function ($request) use ($actor, $assignment, $enterprise) {
        $executionId = $request->context['execution_id'];
        $approval = ApprovalRequest::factory()->create([
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $executionId,
            'actor_id' => $actor->getKey(),
            'approver_id' => $actor->getKey(),
            'capability' => 'work.item.create',
            'target_context' => ['enterprise_id' => $enterprise->getKey()],
            'status' => ApprovalRequest::STATUS_APPROVED,
            'decided_at' => now(),
        ]);

        return new \App\AI\Data\ModelResult(
            text: 'Capability authorized.',
            structured: [
                'answer' => 'Authorized.',
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'capability_requests' => [
                    json_encode([
                        'capability' => 'work.item.create',
                        'expert_slug' => 'analyst',
                        'target_context' => ['enterprise_id' => $enterprise->getKey()],
                        'input_payload' => ['name' => 'Requested work item'],
                        'approval_request_id' => $approval->getKey(),
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'fake-capability',
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Create work.'));

    expect($result->capabilityRequests)->toHaveCount(1)
        ->and($result->capabilityRequests[0])->toBeInstanceOf(CapabilityRequest::class)
        ->and($result->capabilityRequests[0]->capability)->toBe('work.item.create')
        ->and($result->capabilityRequests[0]->toArray())->toMatchArray([
            'capability' => 'work.item.create',
            'target_context' => ['enterprise_id' => $enterprise->getKey()],
            'input_payload' => ['name' => 'Requested work item'],
        ])
        ->and($result->succeeded())->toBeTrue();
});

it('executes interactive capability requests without invoking a ModelProvider', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);

    $provider = new FakeModelProvider(fn () => throw new RuntimeException('Interactive execution must not invoke a ModelProvider.'));

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create the requested work item.',
        mode: \App\Enums\AgentExecutionMode::INTERACTIVE,
        capabilityRequests: [[
            'capability' => 'work.item.create',
            'expert_slug' => 'analyst',
            'target_context' => ['enterprise_id' => $enterprise->getKey()],
            'input_payload' => ['name' => 'Interactive work item'],
        ]],
        correlationId: 'interactive-agent-test',
    ));

    expect($result->execution->mode)->toBe(\App\Enums\AgentExecutionMode::INTERACTIVE)
        ->and($result->execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($result->capabilityRequests)->toHaveCount(1)
        ->and($result->execution->last_result['capability_results'][0]['status'])->toBe('executed')
        ->and(WorkItem::query()->where('name', 'Interactive work item')->exists())->toBeTrue();
});

it('keeps an empty interactive Capability plan waiting for input without invoking a ModelProvider', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);
    $provider = new FakeModelProvider(fn () => throw new RuntimeException('Interactive execution must not invoke a ModelProvider.'));

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Await an interactive Capability plan.',
        mode: \App\Enums\AgentExecutionMode::INTERACTIVE,
        correlationId: 'interactive-empty-plan',
    ));

    expect($result->execution->status)->toBe(AgentExecution::STATUS_WAITING_FOR_INPUT)
        ->and($result->execution->current_step)->toBe(0)
        ->and($result->execution->steps()->count())->toBe(0)
        ->and($result->execution->state_reason)->toContain('waiting for a Capability plan');
});

it('executes an interactive Capability plan as multiple durable steps', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);
    $provider = new FakeModelProvider(fn () => throw new RuntimeException('Interactive execution must not invoke a ModelProvider.'));

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Execute the two-step interactive plan.',
        mode: \App\Enums\AgentExecutionMode::INTERACTIVE,
        capabilityRequests: [
            ['step' => 1, 'capability' => 'work.item.create', 'expert_slug' => 'analyst', 'target_context' => ['enterprise_id' => $enterprise->getKey()], 'input_payload' => ['name' => 'Interactive step one'], 'idempotency_key' => 'interactive-step-one'],
            ['step' => 2, 'capability' => 'work.item.create', 'expert_slug' => 'analyst', 'target_context' => ['enterprise_id' => $enterprise->getKey()], 'input_payload' => ['name' => 'Interactive step two'], 'idempotency_key' => 'interactive-step-two'],
        ],
        correlationId: 'interactive-multi-step',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->current_step)->toBe(2)
        ->and($result->execution->steps()->count())->toBe(2)
        ->and($result->execution->steps()->orderBy('sequence')->pluck('status')->all())
        ->toBe([AgentExecutionStep::STATUS_COMPLETED, AgentExecutionStep::STATUS_COMPLETED])
        ->and($result->execution->steps()->orderBy('sequence')->pluck('type')->all())
        ->toBe([AgentExecutionStep::TYPE_CAPABILITY, AgentExecutionStep::TYPE_CAPABILITY])
        ->and($result->execution->steps()->pluck('correlation_id')->all())
        ->toBe(['interactive-multi-step', 'interactive-multi-step'])
        ->and(WorkItem::query()->whereIn('name', ['Interactive step one', 'Interactive step two'])->count())
        ->toBe(2);
});

it('does not allow an idempotency key to switch Agent execution mode', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);
    $provider = new FakeModelProvider(fn () => new \App\AI\Data\ModelResult(
        text: 'Completed.',
        structured: ['answer' => 'Completed.', 'capability_requests' => [], 'delegation_requests' => [], 'termination' => 'completed'],
        provider: 'fake',
        model: 'test',
        invocationId: 'mode-test',
    ));
    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    );

    $service->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Same request.',
        mode: \App\Enums\AgentExecutionMode::INTERACTIVE,
        idempotencyKey: 'same-mode-key',
    ));

    expect(fn () => $service->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Same request.',
        mode: \App\Enums\AgentExecutionMode::AUTONOMOUS,
        idempotencyKey: 'same-mode-key',
    )))->toThrow(LogicException::class, 'does not match required mode');
});

it('fails the execution when the provider fails', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);

    $provider = new FakeModelProvider(fn () => throw new ModelProviderException(ModelProviderFailureType::Unavailable, 'fake', 'provider unavailable'));

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Run.')))
        ->toThrow(RuntimeException::class, 'provider unavailable');

    $execution = AgentExecution::query()->latest('id')->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and($execution->failure_code)->toBe('provider.unavailable')
        ->and($execution->failure_reason)->toBe('The model provider could not complete the execution.')
        ->and($execution->completed_at)->not->toBeNull();
});

it('fails the execution when a model capability request is not authorized', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);

    $provider = FakeModelProvider::returning(
        structured: [
            'answer' => 'No.',
            'decision_title' => '',
            'decision_summary' => '',
            'decision_rationale' => '',
            'capability_requests' => [
                json_encode(['capability' => 'finance.report.generate', 'expert_slug' => 'finance'], JSON_THROW_ON_ERROR),
            ],
        ],
    );

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Execute finance.')))
        ->toThrow(AuthorizationException::class, 'not authorized for capability [finance.report.generate]');

    $execution = AgentExecution::query()->latest('id')->first();

    expect($execution->status)->toBe(AgentExecution::STATUS_FAILED);
});

it('does not persist a decision when the model did not produce a decision', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);

    $result = (new AgentExecutionService(
        FakeModelProvider::returning(structured: [
            'answer' => 'Informational response.',
            'decision_title' => '',
            'decision_summary' => '',
            'decision_rationale' => '',
            'capability_requests' => [],
        ]),
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(actor: $actor, assignment: $assignment, prompt: 'Explain.'));

    expect($result->decision)->toBeNull()
        ->and(AgentDecision::query()->count())->toBe(0);
});
it('persists bounded multi-step Agent execution state and terminates explicitly', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);
    $calls = 0;

    $provider = new FakeModelProvider(function ($request) use (&$calls) {
        $calls++;

        return new \App\AI\Data\ModelResult(
            text: 'Step '.$calls,
            structured: [
                'answer' => 'Step '.$calls,
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'termination' => $calls === 2 ? 'completed' : 'continue',
                'next_step' => $calls === 1 ? 'Perform the second step.' : null,
                'termination_reason' => $calls === 2 ? 'task_complete' : null,
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'fake-'.$calls,
            correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Run two steps.',
        options: ['max_steps' => 3],
        correlationId: 'multi-step-test',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($result->execution->current_step)->toBe(2)
        ->and($result->execution->state_reason)->toBe('task_complete')
        ->and($result->execution->steps()->count())->toBe(2)
        ->and($result->execution->steps()->pluck('status')->all())->toBe([
            \App\Models\AgentExecutionStep::STATUS_COMPLETED,
            \App\Models\AgentExecutionStep::STATUS_COMPLETED,
        ])
        ->and($calls)->toBe(2);
});

it('pauses and resumes the same Agent execution from durable state', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);
    $calls = 0;

    $provider = new FakeModelProvider(function ($request) use (&$calls) {
        $calls++;

        return new \App\AI\Data\ModelResult(
            text: 'Response '.$calls,
            structured: [
                'answer' => 'Response '.$calls,
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'termination' => $calls === 1 ? 'waiting_for_input' : 'completed',
                'termination_reason' => $calls === 1 ? 'Need user input.' : 'resumed_complete',
                'next_step' => $calls === 1 ? 'Continue after input.' : null,
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'resume-'.$calls,
            correlationId: $request->correlationId,
        );
    });

    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    );

    $first = $service->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Wait for input.',
        correlationId: 'resume-test',
    ));

    expect($first->execution->status)->toBe(AgentExecution::STATUS_WAITING_FOR_INPUT)
        ->and($first->execution->current_step)->toBe(1);

    $resumed = $service->resume($first->execution, $actor);

    expect($resumed->succeeded())->toBeTrue()
        ->and($resumed->execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($resumed->execution->current_step)->toBe(2)
        ->and($resumed->execution->steps()->count())->toBe(2)
        ->and($calls)->toBe(2);
});

it('revalidates runtime policy before asynchronous resume', function () {
    $actor = User::factory()->create();
    $assignment = governedAssignment($actor);
    $provider = new FakeModelProvider(function ($request) {
        return new \App\AI\Data\ModelResult(
            text: 'Waiting',
            structured: [
                'answer' => 'Waiting',
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'termination' => 'waiting_for_input',
                'termination_reason' => 'Awaiting input.',
                'next_step' => 'Resume later.',
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'resume-policy',
            correlationId: $request->correlationId,
        );
    });

    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    );

    $first = $service->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Wait for input.',
        correlationId: 'resume-policy-test',
    ));

    \App\Models\AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $assignment->organization_id,
        'enterprise_id' => $assignment->enterprise_id,
        'enabled' => false,
    ]);

    expect(fn () => $service->resume($first->execution, $actor))
        ->toThrow(AuthorizationException::class, 'disables execution');
});

it('executes a governed capability and feeds its result into the next reasoning step', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);

    $calls = 0;

    $provider = new FakeModelProvider(function ($request) use (&$calls, $enterprise) {
        $calls++;

        if ($calls === 1) {
            return new \App\AI\Data\ModelResult(
                text: 'Create the work item.',
                structured: [
                    'answer' => 'Create the work item.',
                    'decision_title' => '',
                    'decision_summary' => '',
                    'decision_rationale' => '',
                    'capability_requests' => [
                        json_encode([
                            'capability' => 'work.item.create',
                            'expert_slug' => 'analyst',
                            'target_context' => ['enterprise_id' => $enterprise->getKey()],
                            'input_payload' => [
                                'name' => 'Governed capability result',
                                'status' => 'todo',
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'termination' => 'continue',
                    'next_step' => 'Use the operation result to finish the task.',
                ],
                provider: 'fake',
                model: 'test',
                invocationId: 'capability-step-1',
                correlationId: $request->correlationId,
            );
        }

        expect($request->context['previous_result']['capability_results'])->toHaveCount(1)
            ->and($request->context['previous_result']['capability_results'][0]['status'])->toBe('executed')
            ->and($request->context['previous_result']['capability_results'][0]['capability'])->toBe('work.item.create')
            ->and($request->context['previous_result']['capability_results'][0]['result']['name'])->toBe('Governed capability result');

        return new \App\AI\Data\ModelResult(
            text: 'Task completed from the governed operation result.',
            structured: [
                'answer' => 'Task completed from the governed operation result.',
                'decision_title' => 'Work item created',
                'decision_summary' => 'The governed operation created the requested work item.',
                'decision_rationale' => 'The second reasoning step consumed the persisted capability result.',
                'capability_requests' => [],
                'termination' => 'completed',
                'termination_reason' => 'capability_result_consumed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'capability-step-2',
            correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create and then verify a governed work item.',
        correlationId: 'capability-result-loop',
        options: ['max_steps' => 3],
    ));

    $item = WorkItem::query()->where('enterprise_id', $enterprise->getKey())->first();

    expect($calls)->toBe(2)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->execution->current_step)->toBe(2)
        ->and($item)->not->toBeNull()
        ->and($item->name)->toBe('Governed capability result')
        ->and($result->execution->last_result['capability_results'][0]['provenance']['operation'])
        ->toBe(\App\Operations\CreateWorkItem::class);
});

it('delegates from Agent reasoning, links parent and child executions, and feeds the child result back to the parent', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $sourceDescriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass(get_class(new class extends Agent
        {
            public function definition(): \App\Agents\AgentDefinition
            {
                return new \App\Agents\AgentDefinition(
                    name: 'Source Orchestrator',
                    description: 'Delegates governed work.',
                    responsibilities: ['delegate'],
                    instructions: 'Delegate governed work only through the execution boundary.',
                    experts: ['business-analysis'],
                    requiredContext: ['enterprise']
                );
            }
        }))
        ->create(['slug' => 'source-orchestrator']);

    $targetDescriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass(get_class(new class extends Agent
        {
            public function definition(): \App\Agents\AgentDefinition
            {
                return new \App\Agents\AgentDefinition(
                    name: 'Target Operator',
                    description: 'Executes delegated work.',
                    responsibilities: ['execute'],
                    instructions: 'Execute delegated work within the supplied authorized context.',
                    experts: ['analyst'],
                    requiredContext: ['enterprise']
                );
            }
        }))
        ->create(['slug' => 'target-operator']);

    ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);

    $source = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $sourceDescriptor->getKey(),
    ]);
    $target = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $targetDescriptor->getKey(),
    ]);

    $calls = 0;
    $provider = new FakeModelProvider(function ($request) use (&$calls) {
        $calls++;
        $agentSlug = $request->context['agent']['slug'];

        if ($agentSlug === 'source-orchestrator' && $calls === 1) {
            return new \App\AI\Data\ModelResult(
                text: 'Delegate this work.',
                structured: [
                    'answer' => 'Delegate this work.',
                    'decision_title' => '',
                    'decision_summary' => '',
                    'decision_rationale' => '',
                    'capability_requests' => [],
                    'delegation_requests' => [
                        [
                            'target_agent_slug' => 'target-operator',
                            'capability' => 'work.item.create',
                            'expert_slug' => 'analyst',
                            'objective' => 'Perform the delegated operation.',
                            'context_requirements' => ['enterprise'],
                            'target_context' => ['enterprise_id' => $request->context['target_context']['enterprise_id'] ?? null],
                            'idempotency_key' => 'runtime-delegation-1',
                        ],
                    ],
                    'termination' => 'delegated',
                    'termination_reason' => 'delegated_to_specialist',
                    'next_step' => 'Review the delegated result.',
                ],
                provider: 'fake',
                model: 'test',
                invocationId: 'source-step-1',
                correlationId: $request->correlationId,
            );
        }

        if ($agentSlug === 'target-operator') {
            return new \App\AI\Data\ModelResult(
                text: 'Delegated work completed.',
                structured: [
                    'answer' => 'Delegated work completed.',
                    'decision_title' => 'Delegated work complete',
                    'decision_summary' => 'The target Agent completed the delegated objective.',
                    'decision_rationale' => 'The child execution remained within the authorized Enterprise scope.',
                    'capability_requests' => [],
                    'delegation_requests' => [],
                    'termination' => 'completed',
                    'termination_reason' => 'delegated_work_complete',
                ],
                provider: 'fake',
                model: 'test',
                invocationId: 'target-step-1',
                correlationId: $request->correlationId,
            );
        }

        expect($request->context['previous_result']['delegation_results'])->toHaveCount(1)
            ->and($request->context['previous_result']['delegation_results'][0]['target_agent'])->toBe('target-operator')
            ->and($request->context['previous_result']['delegation_results'][0]['execution_status'])->toBe(AgentExecution::STATUS_COMPLETED)
            ->and($request->context['previous_result']['delegation_results'][0]['provenance']['parent_execution_id'])->not->toBeNull();

        return new \App\AI\Data\ModelResult(
            text: 'Parent completed from the delegated result.',
            structured: [
                'answer' => 'Parent completed from the delegated result.',
                'decision_title' => 'Delegation reviewed',
                'decision_summary' => 'The parent Agent consumed the delegated result.',
                'decision_rationale' => 'The child execution result was available as structured prior execution state.',
                'capability_requests' => [],
                'delegation_requests' => [],
                'termination' => 'completed',
                'termination_reason' => 'delegated_result_consumed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'source-step-2',
            correlationId: $request->correlationId,
        );
    });

    app()->instance(\App\AI\Contracts\ModelProvider::class, $provider);

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $source,
        prompt: 'Delegate the operation and review the result.',
        targetContext: ['enterprise_id' => $enterprise->getKey()],
        correlationId: 'delegation-runtime-loop',
        options: ['max_steps' => 3],
    ));

    $delegation = \App\Models\AgentDelegation::query()->firstOrFail();
    $child = AgentExecution::query()->where('agent_assignment_id', $target->getKey())->firstOrFail();

    expect($calls)->toBe(3)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($result->execution->current_step)->toBe(2)
        ->and($delegation->status)->toBe(\App\Models\AgentDelegation::STATUS_SUCCEEDED)
        ->and($delegation->parent_agent_execution_id)->toBe($result->execution->getKey())
        ->and($delegation->target_agent_execution_id)->toBe($child->getKey())
        ->and($child->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($result->execution->last_result['delegation_results'][0]['execution_id'])->toBe($child->getKey());
});

it('rejects a runtime delegation that requests context outside the target Agent contract', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $sourceDescriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass(get_class(new class extends Agent
        {
            public function definition(): \App\Agents\AgentDefinition
            {
                return new \App\Agents\AgentDefinition(name: 'Source', description: 'Delegates.', responsibilities: ['delegate'], instructions: 'Delegate.', experts: ['business-analysis'], requiredContext: ['enterprise']);
            }
        }))
        ->create(['slug' => 'runtime-source']);
    $targetDescriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass(get_class(new class extends Agent
        {
            public function definition(): \App\Agents\AgentDefinition
            {
                return new \App\Agents\AgentDefinition(name: 'Target', description: 'Executes.', responsibilities: ['execute'], instructions: 'Execute.', experts: ['analyst'], requiredContext: ['enterprise']);
            }
        }))
        ->create(['slug' => 'runtime-target']);

    ExpertDescriptor::factory()
        ->forRuntimeClass(testExpertRuntimeClass())
        ->create(['slug' => 'analyst']);

    $source = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $sourceDescriptor->getKey()]);
    $target = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $targetDescriptor->getKey()]);

    $provider = new FakeModelProvider(function ($request) {
        return new \App\AI\Data\ModelResult(
            text: 'Delegate.',
            structured: [
                'answer' => 'Delegate.',
                'decision_title' => '',
                'decision_summary' => '',
                'decision_rationale' => '',
                'capability_requests' => [],
                'delegation_requests' => [[
                    'target_agent_slug' => 'runtime-target',
                    'capability' => 'work.item.create',
                    'expert_slug' => 'analyst',
                    'objective' => 'Perform the operation.',
                    'context_requirements' => ['financial'],
                    'target_context' => [],
                    'idempotency_key' => 'invalid-context-delegation',
                ]],
                'termination' => 'delegated',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'invalid-context',
            correlationId: $request->correlationId,
        );
    });

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $source,
        prompt: 'Delegate with invalid context.',
        correlationId: 'delegation-boundary',
    )))->toThrow(AuthorizationException::class, 'does not permit delegated context requirement [financial]');

    expect(AgentExecution::query()->count())->toBe(1)
        ->and(AgentExecution::query()->firstOrFail()->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and(AgentExecution::query()->where('agent_assignment_id', $target->getKey())->exists())->toBeFalse();
});