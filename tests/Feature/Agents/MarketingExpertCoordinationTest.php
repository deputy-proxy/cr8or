<?php

use App\Agents\MarketingAgent;
use App\Data\ExpertInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Services\ExpertInvocationService;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

function marketingExpertCoordinationSetup(array $capabilities = ['strategy.create', 'strategy.update']): array
{
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();

    $assignment = AgentAssignment::factory()
        ->forEnterprise($enterprise)
        ->create([
            'agent_descriptor_id' => $descriptor->getKey(),
        ]);

    foreach ($capabilities as $capability) {
        AgentPermission::factory()->create([
            'agent_assignment_id' => $assignment->getKey(),
            'capability' => $capability,
        ]);
    }

    $execution = AgentExecution::factory()
        ->forAssignment($assignment)
        ->executing()
        ->create([
            'actor_id' => $actor->getKey(),
            'correlation_id' => 'marketing-expert-coordination',
        ]);

    return [$actor, $enterprise, $assignment, $execution];
}

function invokeMarketingExpert(
    User $actor,
    Enterprise $enterprise,
    AgentAssignment $assignment,
    AgentExecution $execution,
    string $expertSlug,
    array $context = [],
): \App\Data\ExpertInvocationResult {
    $context = $context ?: app(\App\Services\McpContextAssembler::class)
        ->forAgent(
            $actor,
            $enterprise,
            ['enterprise', 'strategy', 'work', 'knowledge', 'decisions', 'execution_history'],
            [],
            $assignment,
        )
        ->toArray();

    return app(ExpertInvocationService::class)->invoke(new ExpertInvocationRequest(
        actor: $actor,
        assignment: $assignment,
        execution: $execution,
        agent: app(MarketingAgent::class),
        expertSlug: $expertSlug,
        businessObjective: 'Coordinate governed marketing work.',
        authorizedContext: $context,
        expectedReasoningOutput: 'Return specialized reasoning and governed Capability requests.',
        targetContext: ['enterprise_id' => $enterprise->getKey()],
        correlationId: $execution->correlation_id,
    ));
}

it('selects multiple Marketing Experts deterministically from runtime routing metadata', function (): void {
    $agent = app(MarketingAgent::class);

    expect($agent->expertsFor('coordinate marketing expertise'))
        ->toBe(['marketing', 'strategy', 'copywriting', 'seo'])
        ->and($agent->expertsFor('plan marketing activity'))
        ->toBe(['marketing', 'strategy'])
        ->and($agent->expertsFor('coordinate campaign and content work'))
        ->toBe(['copywriting', 'seo']);
});

it('keeps Expert context bounded to the Expert declaration', function (): void {
    [$actor, $enterprise, $assignment, $execution] = marketingExpertCoordinationSetup();

    $context = app(\App\Services\McpContextAssembler::class)
        ->forAgent(
            $actor,
            $enterprise,
            ['enterprise', 'strategy', 'work', 'knowledge', 'decisions', 'execution_history'],
            [],
            $assignment,
        )
        ->toArray();

    $context['unauthorized_extra'] = ['must_not_be_visible_to_expert'];

    $result = invokeMarketingExpert(
        $actor,
        $enterprise,
        $assignment,
        $execution,
        'strategy',
        $context,
    );

    expect($result->succeeded())->toBeTrue()
        ->and($result->reasoningOutput['available_context'])->toBe(['enterprise', 'strategy', 'invocation'])
        ->and($result->metadata['authorized_context'])->toBe(['enterprise', 'strategy']);
});

it('denies a missing routed Expert before business reasoning continues', function (): void {
    [$actor, $enterprise, $assignment, $execution] = marketingExpertCoordinationSetup();

    ExpertDescriptor::query()->where('slug', 'strategy')->delete();

    expect(fn () => invokeMarketingExpert(
        $actor,
        $enterprise,
        $assignment,
        $execution,
        'strategy',
    ))->toThrow(AuthorizationException::class, 'could not be resolved');
});

it('denies an Expert Capability that the parent Agent assignment does not permit', function (): void {
    [$actor, $enterprise, $assignment, $execution] = marketingExpertCoordinationSetup([
        'marketing.plan',
    ]);

    expect(fn () => invokeMarketingExpert(
        $actor,
        $enterprise,
        $assignment,
        $execution,
        'copywriting',
    ))->toThrow(
        AuthorizationException::class,
        'not authorized to use capability [marketing.content.create]',
    );
});

it('preserves the parent AgentExecution correlation for successful Expert Capability requests', function (): void {
    [$actor, $enterprise, $assignment, $execution] = marketingExpertCoordinationSetup([
        'marketing.content.create',
        'marketing.content.update',
        'marketing.content.review',
    ]);

    $result = invokeMarketingExpert(
        $actor,
        $enterprise,
        $assignment,
        $execution,
        'copywriting',
    );

    expect($result->succeeded())
        ->toBeTrue()
        ->and($result->requestedCapabilities)->toHaveCount(1)
        ->and($result->requestedCapabilities[0]->capability)->toBe('marketing.content.create')
        ->and($result->requestedCapabilities[0]->expertSlug)->toBe('copywriting')
        ->and($result->requestedCapabilities[0]->execution->getKey())->toBe($execution->getKey())
        ->and($result->requestedCapabilities[0]->resolvedCorrelationId())->toBe($execution->correlation_id);
});