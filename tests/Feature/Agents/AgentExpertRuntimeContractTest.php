<?php

use App\Agents\Agent;
use App\Agents\AgentDefinition;
use App\Data\AgentContext;
use App\Data\AgentContextSection;
use App\Data\CapabilityRequest;
use App\Data\ExpertInvocationRequest;
use App\Data\ExpertInvocationResult;
use App\Experts\Expert;
use App\Experts\ExpertDefinition;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Services\ExpertInvocationService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Tests\Support\Agents\RuntimeContractInvalidExpert;
use Tests\Support\Agents\RuntimeContractTestAgent;
use Tests\Support\Agents\RuntimeContractTestExpert;

uses()->group('agent-expert-runtime-contract');

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

function registeredAgentRuntimes(): array
{
    return [
        'ceo' => \App\Agents\CeoAgent::class,
        'marketing' => \App\Agents\MarketingAgent::class,
        'finance' => \App\Agents\FinanceAgent::class,
        'product' => \App\Agents\ProductAgent::class,
        'operations' => \App\Agents\OperationsAgent::class,
    ];
}

function registeredExpertRuntimes(): array
{
    return [
        'business-analysis' => \App\Experts\BusinessAnalysisExpert::class,
        'copywriting' => \App\Experts\CopywritingExpert::class,
        'marketing' => \App\Experts\MarketingExpert::class,
        'finance' => \App\Experts\FinanceExpert::class,
        'product' => \App\Experts\ProductExpert::class,
        'seo' => \App\Experts\SeoExpert::class,
        'strategy' => \App\Experts\StrategyExpert::class,
        'operations' => \App\Experts\OperationsExpert::class,
    ];
}

function runtimeContractExecution(string $agentSlug, string $expertSlug): array
{
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $agentDescriptor = AgentDescriptor::query()
        ->where('slug', $agentSlug)
        ->firstOrFail();

    $assignment = AgentAssignment::factory()
        ->forEnterprise($enterprise)
        ->create([
            'agent_descriptor_id' => $agentDescriptor->getKey(),
        ]);

    $agent = app($agentDescriptor->resolveRuntimeClass());
    $expert = app(ExpertDescriptor::query()->where('slug', $expertSlug)->firstOrFail()->resolveRuntimeClass());

    foreach (array_unique([...$agent->capabilities(), ...$expert->capabilities()]) as $capability) {
    }

    $execution = AgentExecution::factory()
        ->forAssignment($assignment)
        ->executing()
        ->create([
            'actor_id' => $actor->getKey(),
            'correlation_id' => 'runtime-contract-'.$agentSlug,
        ]);

    return [$actor, $enterprise, $assignment, $execution, $agent];
}

it('covers every registered Agent runtime and treats PHP metadata as authoritative', function (): void {
    $descriptors = AgentDescriptor::query()->orderBy('slug')->get()->keyBy('slug');

    expect($descriptors->keys()->sort()->values()->all())->toBe(collect(array_keys(registeredAgentRuntimes()))->sort()->values()->all());

    foreach (registeredAgentRuntimes() as $slug => $expectedClass) {
        $descriptor = $descriptors->get($slug);
        $agent = app($descriptor->resolveRuntimeClass());
        $definition = $agent->definition();

        expect($descriptor->runtime_class)->toBe($expectedClass)
            ->and($agent)->toBeInstanceOf(Agent::class)
            ->and($definition)->toBeInstanceOf(AgentDefinition::class)
            ->and($definition->name)->toBe($agent->name())
            ->and($definition->description)->toBe($agent->description())
            ->and($definition->responsibilities)->toBe($agent->responsibilities())
            ->and($definition->instructions)->toBe($agent->instructions())
            ->and($definition->experts)->toBe($agent->experts())
            ->and($definition->requiredContext)->toBe($agent->requiredContext())
            ->and($definition->capabilities)->toBe($agent->capabilities())
            ->and($definition->decisionBoundaries)->toBe($agent->decisionBoundaries())
            ->and($definition->expectedOutputs)->toBe($agent->expectedOutputs())
            ->and($definition->capabilityMap)->toBe($agent->capabilityMap())
            ->and($definition->capabilityGaps)->toBe($agent->capabilityGaps())
            ->and($definition->approvalSensitiveCapabilities)->toBe($agent->approvalSensitiveCapabilities())
            ->and($agent->name())->not->toBeEmpty()
            ->and($agent->description())->not->toBeEmpty()
            ->and($agent->responsibilities())->not->toBeEmpty()
            ->and($agent->instructions())->not->toBeEmpty()
            ->and($agent->requiredContext())->not->toBeEmpty()
            ->and($agent->experts())->not->toBeEmpty();
    }
});

it('covers every registered Expert runtime and its declared reasoning contract', function (): void {
    $descriptors = ExpertDescriptor::query()->orderBy('slug')->get()->keyBy('slug');

    expect($descriptors->keys()->sort()->values()->all())->toBe(collect(array_keys(registeredExpertRuntimes()))->sort()->values()->all());

    foreach (registeredExpertRuntimes() as $slug => $expectedClass) {
        $descriptor = $descriptors->get($slug);
        $expert = app($descriptor->resolveRuntimeClass());
        $definition = $expert->definition();

        expect($descriptor->runtime_class)->toBe($expectedClass)
            ->and($expert)->toBeInstanceOf(Expert::class)
            ->and($definition)->toBeInstanceOf(ExpertDefinition::class)
            ->and($definition->name)->toBe($expert->name())
            ->and($definition->description)->toBe($expert->description())
            ->and($definition->responsibilities)->toBe($expert->responsibilities())
            ->and($definition->methodology)->toBe($expert->methodology())
            ->and($definition->requiredContext)->toBe($expert->requiredContext())
            ->and($definition->capabilities)->toBe($expert->capabilities())
            ->and($expert->name())->not->toBeEmpty()
            ->and($expert->description())->not->toBeEmpty()
            ->and($expert->responsibilities())->not->toBeEmpty()
            ->and($expert->methodology())->not->toBeEmpty()
            ->and($expert->requiredContext())->not->toBeEmpty();

        $result = $expert->analyze(array_fill_keys($expert->requiredContext(), ['authorized' => true]));

        expect($result)->toBeArray();
    }
});

it('assembles the canonical context required by every registered Agent', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    foreach (registeredAgentRuntimes() as $class) {
        $agent = app($class);
        $context = app(\App\Services\McpContextAssembler::class)
            ->forAgent($actor, $enterprise, $agent->requiredContext());

        expect($context)->toBeInstanceOf(AgentContext::class)
            ->and($context->section('enterprise'))->not->toBeNull()
            ->and($context->section('enterprise_context'))->not->toBeNull();

        $serialized = $context->toArray();

        foreach ($agent->requiredContext() as $requiredContext) {
            expect($serialized)->toHaveKey($requiredContext);
        }

        foreach ($context->sections() as $section) {
            expect($section)->toBeInstanceOf(AgentContextSection::class)
                ->and($section->name)->not->toBeEmpty()
                ->and($section->source)->not->toBeEmpty()
                ->and($section->scope)->toMatchArray([
                    'organization_id' => $enterprise->organization_id,
                    'enterprise_id' => $enterprise->getKey(),
                ]);
        }
    }
});

it('invokes every registered Agent-to-Expert pairing through the canonical Expert contract', function (): void {
    $pairs = [
        'ceo' => 'business-analysis',
        'marketing' => 'marketing',
        'finance' => 'finance',
        'product' => 'product',
        'operations' => 'operations',
    ];

    foreach ($pairs as $agentSlug => $expertSlug) {
        [$actor, $enterprise, $assignment, $execution, $agent] = runtimeContractExecution($agentSlug, $expertSlug);

        $context = app(\App\Services\McpContextAssembler::class)
            ->forAgent($actor, $enterprise, $agent->requiredContext())
            ->toArray();

        $result = app(ExpertInvocationService::class)->invoke(new ExpertInvocationRequest(
            actor: $actor,
            assignment: $assignment,
            execution: $execution,
            agent: $agent,
            expertSlug: $expertSlug,
            businessObjective: 'Validate the registered Agent-to-Expert runtime contract.',
            authorizedContext: $context,
            expectedReasoningOutput: 'Return deterministic provider-independent reasoning output.',
            targetContext: ['enterprise_id' => $enterprise->getKey()],
            correlationId: $execution->correlation_id,
        ));

        expect($result)->toBeInstanceOf(ExpertInvocationResult::class)
            ->and($result->succeeded())->toBeTrue()
            ->and($result->expertSlug)->toBe($expertSlug)
            ->and($result->runtimeClass)->toBe(registeredExpertRuntimes()[$expertSlug])
            ->and($result->agentExecutionId)->toBe($execution->getKey())
            ->and($result->correlationId)->toBe($execution->correlation_id)
            ->and($result->reasoningOutput)->toBeArray();
    }
});

it('propagates the canonical correlation and Capability Request through Expert execution', function (): void {
    [$actor, $enterprise, $assignment, $execution] = runtimeContractExecution('operations', 'operations');

    $agent = app(RuntimeContractTestAgent::class);
    $descriptor = ExpertDescriptor::factory()
        ->forRuntimeClass(RuntimeContractTestExpert::class)
        ->create(['slug' => 'runtime-contract-expert']);

    $agent->definition();
    $agentDescriptor = AgentDescriptor::factory()->forRuntimeClass(RuntimeContractTestAgent::class)->create(['slug' => 'runtime-contract-test-agent']);
    $assignment->update(['agent_descriptor_id' => $agentDescriptor->getKey()]);
    $assignment->load('agentDescriptor');

    $context = [
        'enterprise' => [
            'enterprise' => [
                'id' => $enterprise->getKey(),
            ],
        ],
    ];

    $result = app(ExpertInvocationService::class)->invoke(new ExpertInvocationRequest(
        actor: $actor,
        assignment: $assignment,
        execution: $execution,
        agent: $agent,
        expertSlug: $descriptor->slug,
        businessObjective: 'Validate Capability Request correlation.',
        authorizedContext: $context,
        expectedReasoningOutput: 'Return one governed Capability request.',
        targetContext: ['enterprise_id' => $enterprise->getKey()],
        correlationId: $execution->correlation_id,
    ));

    expect($result->requestedCapabilities)->toHaveCount(1)
        ->and($result->requestedCapabilities[0])->toBeInstanceOf(CapabilityRequest::class)
        ->and($result->requestedCapabilities[0]->expertSlug)->toBe('runtime-contract-expert')
        ->and($result->requestedCapabilities[0]->assignment->getKey())->toBe($assignment->getKey())
        ->and($result->requestedCapabilities[0]->execution->getKey())->toBe($execution->getKey())
        ->and($result->requestedCapabilities[0]->resolvedCorrelationId())->toBe($execution->correlation_id)
        ->and($result->requestedCapabilities[0]->toArray())->toMatchArray([
            'capability' => 'work.item.create',
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
            'expert_slug' => 'runtime-contract-expert',
            'correlation_id' => $execution->correlation_id,
        ]);
});

it('rejects invalid runtime implementations deterministically', function (): void {
    expect(fn () => new AgentDefinition(
        name: '',
        description: 'Invalid',
        responsibilities: ['validate'],
        instructions: 'Invalid',
        experts: [],
        requiredContext: ['enterprise'],
        capabilities: [],
    ))->toThrow(InvalidArgumentException::class, 'Agent identity must define a name.');

    expect(fn () => new ExpertDefinition(
        name: 'Invalid',
        description: 'Invalid',
        responsibilities: [],
        methodology: 'Invalid',
        requiredContext: ['enterprise'],
        capabilities: [],
    ))->toThrow(InvalidArgumentException::class, 'must define responsibilities');

    expect(fn () => new AgentContext([
        'wrong' => new AgentContextSection(
            name: 'enterprise',
            data: [],
            source: 'test',
            scope: [],
        ),
    ]))->toThrow(InvalidArgumentException::class, 'keys must match');

    [$actor, $enterprise, $assignment, $execution] = runtimeContractExecution('operations', 'operations');

    $agent = new RuntimeContractTestAgent;

    $invalidDescriptor = ExpertDescriptor::factory()
        ->forRuntimeClass(RuntimeContractInvalidExpert::class)
        ->create(['slug' => 'invalid-contract-expert']);

    $agentWithInvalidExpert = new class extends Agent
    {
        public function definition(): AgentDefinition
        {
            return new AgentDefinition(
                name: 'Invalid Expert Request Agent',
                description: 'Requests an invalid Expert output.',
                responsibilities: ['validate'],
                instructions: 'Validate invalid Expert output.',
                experts: ['invalid-contract-expert'],
                requiredContext: ['enterprise'],
                capabilities: ['work.item.create'],
            );
        }
    };

    expect(fn () => app(ExpertInvocationService::class)->invoke(new ExpertInvocationRequest(
        actor: $actor,
        assignment: $assignment,
        execution: $execution,
        agent: $agentWithInvalidExpert,
        expertSlug: $invalidDescriptor->slug,
        businessObjective: 'Validate invalid Expert output.',
        authorizedContext: [
            'enterprise' => [
                'enterprise' => ['id' => $enterprise->getKey()],
            ],
        ],
        expectedReasoningOutput: 'Reject malformed Capability requests.',
        targetContext: ['enterprise_id' => $enterprise->getKey()],
        correlationId: $execution->correlation_id,
    )))->toThrow(AuthorizationException::class, 'not authorized to use capability [work.item.create] through Expert [invalid-contract-expert]');
});
