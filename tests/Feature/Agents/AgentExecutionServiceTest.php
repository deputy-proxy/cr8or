<?php

use App\Agents\Agent;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
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
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

function testAgentRuntimeClass(): string
{
    return get_class(new class extends Agent
    {
        public function name(): string
        {
            return 'Planner';
        }

        public function description(): string
        {
            return 'Plans governed enterprise work.';
        }

        public function responsibilities(): array
        {
            return ['plan'];
        }

        public function capabilities(): array
        {
            return ['work.create'];
        }

        public function requiredContext(): array
        {
            return ['enterprise', 'knowledge', 'strategy', 'work'];
        }
    });
}

function testExpertRuntimeClass(): string
{
    return get_class(new class extends Expert
    {
        public function name(): string
        {
            return 'Analyst';
        }

        public function description(): string
        {
            return 'Analyzes enterprise context.';
        }

        public function responsibilities(): array
        {
            return ['analyze'];
        }

        public function capabilities(): array
        {
            return ['analysis.read'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }

        public function methodology(): string
        {
            return 'Evidence-first analysis.';
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
    ))->execute($actor, $assignment, 'Create a plan.', modelOptions: ['correlation_id' => 'agent-test-123']);

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
    ))->execute($actor, $assignment, 'Run.'))
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
    ))->execute($actor, $assignment, 'Run.'))
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
            ->and($request->context['experts']['results'][0]['result'])->toBe([
                'enterprise' => $request->context['enterprise']['enterprise']['slug'],
            ]);

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
    ))->execute($actor, $assignment, 'Analyze.', expertSlugs: [$expert->slug]);

    expect($result->succeeded())->toBeTrue();
});

it('re-authorizes a state-changing capability and requires approval when configured', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $assignment = governedAssignment($actor, $enterprise);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.create',
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
            'capability' => 'work.create',
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
                        'capability' => 'work.create',
                        'target_context' => ['enterprise_id' => $enterprise->getKey()],
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
    ))->execute($actor, $assignment, 'Create work.');

    expect($result->capabilityRequests)->toHaveCount(1)
        ->and($result->capabilityRequests[0]['capability'])->toBe('work.create')
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
    ))->execute($actor, $assignment, 'Run.'))
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
                json_encode(['capability' => 'finance.execute'], JSON_THROW_ON_ERROR),
            ],
        ],
    );

    expect(fn () => (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(\App\Services\AgentCapabilityAuthorizer::class),
    ))->execute($actor, $assignment, 'Execute finance.'))
        ->toThrow(AuthorizationException::class, 'not authorized for capability [finance.execute]');

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
    ))->execute($actor, $assignment, 'Explain.');

    expect($result->decision)->toBeNull()
        ->and(AgentDecision::query()->count())->toBe(0);
});
