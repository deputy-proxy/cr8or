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
use App\Models\AgentPermission;
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
            return new \App\Agents\AgentDefinition(name: 'Planner', description: 'Plans governed enterprise work.', responsibilities: ['plan'], instructions: 'Plan governed enterprise work within the supplied authorized context.', experts: ['analyst', 'unauthorized-analyst'], requiredContext: ['enterprise', 'knowledge', 'strategy', 'work'], capabilities: ['work.item.create']);
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

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

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

it('denies an Expert whose declared capability is not permitted by the Agent assignment', function () {
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
        ->toThrow(AuthorizationException::class, 'not authorized to use capability [work.item.create]');
});

it('re-authorizes a state-changing capability and requires approval when configured', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
        'requires_approval' => true,
    ]);

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
                json_encode(['capability' => 'finance.report.generate'], JSON_THROW_ON_ERROR),
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

it('executes a governed capability and feeds its result into the next reasoning step', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

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

it('pauses for capability approval, resumes, and executes only after approval', function () {
    $actor = User::factory()->create();
    $approver = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    Membership::factory()->admin()->create([
        'user_id' => $approver->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
        'requires_approval' => true,
    ]);

    $calls = 0;

    $provider = new FakeModelProvider(function ($request) use (&$calls, $enterprise) {
        $calls++;

        if ($calls === 1) {
            return new \App\AI\Data\ModelResult(
                text: 'Approval required.',
                structured: [
                    'answer' => 'Approval required.',
                    'decision_title' => 'Create work item',
                    'decision_summary' => 'Human approval is required.',
                    'decision_rationale' => 'The assigned capability is approval-sensitive.',
                    'capability_requests' => [
                        json_encode([
                            'capability' => 'work.item.create',
                            'target_context' => ['enterprise_id' => $enterprise->getKey()],
                            'input_payload' => ['name' => 'Approved work item'],
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'termination' => 'continue',
                    'next_step' => 'Resume after approval.',
                ],
                provider: 'fake',
                model: 'test',
                invocationId: 'approval-step-1',
                correlationId: $request->correlationId,
            );
        }

        $approval = ApprovalRequest::query()
            ->where('agent_execution_id', $request->context['execution_id'])
            ->where('capability', 'work.item.create')
            ->firstOrFail();

        expect($approval->status)->toBe(ApprovalRequest::STATUS_APPROVED);

        return new \App\AI\Data\ModelResult(
            text: 'Approved operation completed.',
            structured: [
                'answer' => 'Approved operation completed.',
                'decision_title' => 'Work item created',
                'decision_summary' => 'The approved work item was created.',
                'decision_rationale' => 'The operation ran only after approval.',
                'capability_requests' => [
                    json_encode([
                        'capability' => 'work.item.create',
                        'target_context' => ['enterprise_id' => $enterprise->getKey()],
                        'input_payload' => ['name' => 'Approved work item'],
                        'approval_request_id' => $approval->getKey(),
                    ], JSON_THROW_ON_ERROR),
                ],
                'termination' => 'completed',
                'termination_reason' => 'approved_capability_executed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'approval-step-2',
            correlationId: $request->correlationId,
        );
    });

    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    );

    $waiting = $service->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create an approved work item.',
        correlationId: 'approval-loop',
    ));

    $approval = ApprovalRequest::query()
        ->where('agent_execution_id', $waiting->execution->getKey())
        ->firstOrFail();

    expect($waiting->execution->status)->toBe(AgentExecution::STATUS_WAITING_FOR_APPROVAL)
        ->and($approval->status)->toBe(ApprovalRequest::STATUS_PENDING)
        ->and(WorkItem::query()->where('name', 'Approved work item')->exists())->toBeFalse();

    app(\App\Services\ApprovalRequestService::class)->approve(
        $approval,
        $approver,
        'Approved by the authorized human.',
    );

    $resumed = $service->resume($waiting->execution->refresh(), $actor);

    expect($calls)->toBe(2)
        ->and($resumed->succeeded())->toBeTrue()
        ->and(WorkItem::query()->where('name', 'Approved work item')->exists())->toBeTrue()
        ->and($approval->refresh()->consumed_agent_execution_id)->toBe($waiting->execution->getKey());
});