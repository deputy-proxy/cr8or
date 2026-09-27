<?php

use App\Agents\Agent;
use App\Agents\AgentDefinition;
use App\Data\ExpertInvocationRequest;
use App\Experts\Expert;
use App\Experts\ExpertDefinition;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Services\ExpertInvocationService;
use Illuminate\Auth\Access\AuthorizationException;

final class ContractTestAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Contract Agent',
            description: 'Tests the governed Expert invocation contract.',
            responsibilities: ['coordinate'],
            instructions: 'Coordinate Expert reasoning within authorized context.',
            experts: ['contract-expert', 'failing-expert', 'capability-expert'],
            requiredContext: ['enterprise'],
            capabilities: [],
        );
    }
}

final class ContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Contract Expert',
            description: 'Provides contract test reasoning.',
            responsibilities: ['analyze'],
            methodology: 'Evidence-first analysis.',
            requiredContext: ['enterprise', 'strategy'],
            capabilities: ['work.item.create'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'answer' => 'Reasoned result.',
            'decisions' => [['title' => 'Proceed', 'summary' => 'Context supports proceeding.']],
            'recommendations' => [['action' => 'Proceed']],
        ];
    }
}

final class FailingContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Failing Expert',
            description: 'Fails during reasoning.',
            responsibilities: ['fail'],
            methodology: 'Deliberately failing test reasoning.',
            requiredContext: ['enterprise'],
            capabilities: [],
        );
    }

    public function analyze(array $context): array
    {
        throw new RuntimeException('reasoning failed');
    }
}

final class CapabilityContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Capability Expert',
            description: 'Requests a governed Capability.',
            responsibilities: ['request'],
            methodology: 'Evidence-first capability request.',
            requiredContext: ['enterprise'],
            capabilities: ['work.item.create'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'answer' => 'Requesting governed work.',
            'capability_requests' => [
                [
                    'capability' => 'work.item.create',
                    'target_context' => ['enterprise_id' => $context['enterprise']['enterprise']['id']],
                ],
            ],
        ];
    }
}

function invocationContractContext(Enterprise $enterprise): array
{
    return [
        'enterprise' => [
            'enterprise' => [
                'id' => $enterprise->getKey(),
                'slug' => 'test-enterprise',
            ],
        ],
        'strategy' => ['items' => []],
    ];
}

function invocationContractSetup(string $agentClass = ContractTestAgent::class, string $expertSlug = 'contract-expert'): array
{
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass($agentClass)
        ->create(['slug' => 'contract-agent']);

    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'work.item.create',
    ]);

    ExpertDescriptor::factory()->forRuntimeClass(
        match ($expertSlug) {
            'failing-expert' => FailingContractTestExpert::class,
            'capability-expert' => CapabilityContractTestExpert::class,
            default => ContractTestExpert::class,
        },
    )->create(['slug' => $expertSlug]);

    $execution = AgentExecution::factory()->forAssignment($assignment)->executing()->create([
        'actor_id' => $actor->getKey(),
        'correlation_id' => 'expert-contract-123',
    ]);

    return [$actor, $assignment, $execution, $enterprise];
}

function invokeContract(
    User $actor,
    AgentAssignment $assignment,
    AgentExecution $execution,
    Enterprise $enterprise,
    string $expertSlug = 'contract-expert',
    array $context = [],
): \App\Data\ExpertInvocationResult {
    return app(ExpertInvocationService::class)->invoke(new ExpertInvocationRequest(
        actor: $actor,
        assignment: $assignment,
        execution: $execution,
        agent: app(ContractTestAgent::class),
        expertSlug: $expertSlug,
        businessObjective: 'Assess the current enterprise situation.',
        authorizedContext: $context ?: invocationContractContext($enterprise),
        expectedReasoningOutput: 'Provide evidence-based reasoning and recommendations.',
        targetContext: ['enterprise_id' => $enterprise->getKey()],
        correlationId: 'expert-contract-123',
    ));
}

it('returns a canonical successful invocation linked to the parent AgentExecution', function () {
    [$actor, $assignment, $execution, $enterprise] = invocationContractSetup();

    $result = invokeContract($actor, $assignment, $execution, $enterprise);

    expect($result->succeeded())->toBeTrue()
        ->and($result->expertSlug)->toBe('contract-expert')
        ->and($result->expertName)->toBe('Contract Expert')
        ->and($result->runtimeClass)->toBe(ContractTestExpert::class)
        ->and($result->agentExecutionId)->toBe($execution->getKey())
        ->and($result->correlationId)->toBe('expert-contract-123')
        ->and($result->reasoningOutput['answer'])->toBe('Reasoned result.')
        ->and($result->decisions)->toHaveCount(1)
        ->and($result->recommendations)->toHaveCount(1);
});

it('denies an Expert that is not declared by the receiving Agent', function () {
    [$actor, $assignment, $execution, $enterprise] = invocationContractSetup();
    $agent = new class extends Agent
    {
        public function definition(): AgentDefinition
        {
            return new AgentDefinition(
                name: 'Restricted Agent',
                description: 'Does not declare the requested Expert.',
                responsibilities: ['coordinate'],
                instructions: 'Coordinate only declared Experts.',
                experts: [],
                requiredContext: ['enterprise'],
                capabilities: [],
            );
        }
    };

    expect(fn () => app(ExpertInvocationService::class)->invoke(new ExpertInvocationRequest(
        actor: $actor,
        assignment: $assignment,
        execution: $execution,
        agent: $agent,
        expertSlug: 'contract-expert',
        businessObjective: 'Assess the current enterprise situation.',
        authorizedContext: invocationContractContext($enterprise),
        expectedReasoningOutput: 'Provide evidence-based reasoning.',
        correlationId: 'expert-contract-123',
    )))->toThrow(AuthorizationException::class, 'not declared by Agent');
});

it('fails when the Expert required context is missing', function () {
    [$actor, $assignment, $execution, $enterprise] = invocationContractSetup();

    expect(fn () => invokeContract(
        $actor,
        $assignment,
        $execution,
        $enterprise,
        context: ['enterprise' => invocationContractContext($enterprise)['enterprise']],
    ))->toThrow(AuthorizationException::class, 'missing required context');
});

it('returns a failed invocation when Expert reasoning fails', function () {
    [$actor, $assignment, $execution, $enterprise] = invocationContractSetup(expertSlug: 'failing-expert');

    $result = invokeContract(
        $actor,
        $assignment,
        $execution,
        $enterprise,
        expertSlug: 'failing-expert',
    );

    expect($result->failed())->toBeTrue()
        ->and($result->failure?->code)->toBe('internal.error')
        ->and($result->metadata['failure_reason'])->toBe('reasoning failed');
});

it('returns governed Capability requests only when the Expert declares and the Agent permits them', function () {
    [$actor, $assignment, $execution, $enterprise] = invocationContractSetup(expertSlug: 'capability-expert');

    $result = invokeContract(
        $actor,
        $assignment,
        $execution,
        $enterprise,
        expertSlug: 'capability-expert',
    );

    expect($result->succeeded())->toBeTrue()
        ->and($result->requestedCapabilities)->toHaveCount(1)
        ->and($result->requestedCapabilities[0]->capability)->toBe('work.item.create')
        ->and($result->requestedCapabilities[0]->targetContext)->toBe(['enterprise_id' => $enterprise->getKey()]);
});
