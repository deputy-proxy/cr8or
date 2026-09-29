<?php

use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
use App\Data\CapabilityRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\Membership;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

function marketingEndToEndFixture(): array
{
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $objective = \App\Models\Objective::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);
    $strategy = Strategy::factory()->create([
        'objective_id' => $objective->getKey(),
    ]);
    KnowledgeContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);

    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()
        ->forEnterprise($enterprise)
        ->create(['agent_descriptor_id' => $descriptor->getKey()]);

    return compact('actor', 'enterprise', 'strategy', 'descriptor', 'assignment');
}

function marketingEndToEndService(FakeModelProvider $provider): AgentExecutionService
{
    return new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    );
}

it('completes a governed Marketing Agent execution through Capability, Operation and result', function (): void {
    $fixture = marketingEndToEndFixture();
    extract($fixture);

    $provider = new FakeModelProvider(function ($request) use ($enterprise, $strategy): ModelResult {
        expect($request->context)->toHaveKeys([
            'enterprise',
            'strategy',
            'work',
            'knowledge',
            'decisions',
            'execution_history',
            'instructions',
            'agent',
            'experts',
            'target_context',
        ])
            ->and($request->context['strategy']['objectives'][0]['strategies'][0]['id'])
            ->toBe($strategy->getKey())
            ->and($request->context['experts']['results'][0]['expert'])
            ->toBe('Marketing');

        return new ModelResult(
            text: 'Marketing plan prepared.',
            structured: [
                'answer' => 'Marketing plan prepared.',
                'decision_title' => 'Marketing plan',
                'decision_summary' => 'The authorized enterprise context supports the requested plan.',
                'decision_rationale' => 'Marketing Agent and Expert reasoning stayed within the assigned Enterprise.',
                'capability_requests' => [
                    json_encode([
                        'capability' => 'marketing.plan',
                        'expert_slug' => 'marketing',
                        'target_context' => ['enterprise_id' => $enterprise->getKey()],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'marketing-e2e-success',
            correlationId: $request->correlationId,
        );
    });

    $result = marketingEndToEndService($provider)->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Prepare a governed marketing plan.',
        expertSlugs: ['marketing'],
        correlationId: 'marketing-e2e-success',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(AgentExecution::STATUS_SUCCEEDED)
        ->and($result->execution->enterprise_id)->toBe($enterprise->getKey())
        ->and($result->execution->agent_slug)->toBe('marketing')
        ->and($result->execution->provider)->toBe('fake')
        ->and($result->execution->external_execution_id)->toBe('marketing-e2e-success')
        ->and($result->execution->correlation_id)->toBe('marketing-e2e-success')
        ->and($result->execution->completed_at)->not->toBeNull()
        ->and($result->decision)->toBeInstanceOf(AgentDecision::class)
        ->and($result->decision->execution_id)->toBe($result->execution->getKey())
        ->and($result->capabilityRequests)->toHaveCount(1)
        ->and($result->capabilityRequests[0])->toBeInstanceOf(CapabilityRequest::class);

    $capabilityRequest = $result->capabilityRequests[0];

    $operationResult = app(CapabilityRegistry::class)
        ->operation($capabilityRequest->capability)
        ->execute($actor, [
            'enterprise' => $enterprise,
            'target_context' => $capabilityRequest->targetContext,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $result->execution->getKey(),
        ]);

    expect($operationResult)
        ->toHaveKey('capability', 'marketing.plan')
        ->and($operationResult['expert']['slug'])->toBe('marketing')
        ->and($operationResult['analysis']['focus'])->toBe('marketing planning')
        ->and($operationResult['context_categories'])->toContain('enterprise', 'strategy', 'knowledge');
});

it('does not allow an approval-sensitive Marketing capability to bypass approval', function (): void {
    $fixture = marketingEndToEndFixture();
    extract($fixture);

    $provider = new FakeModelProvider(function ($request): ModelResult {
        return new ModelResult(
            text: 'Approval-sensitive work requested.',
            structured: [
                'answer' => 'Approval-sensitive work requested.',
                'decision_title' => 'Publication readiness',
                'decision_summary' => 'Human approval is required.',
                'capability_requests' => [
                    json_encode([
                        'capability' => 'marketing.content.publication-ready',
                        'expert_slug' => 'marketing',
                        'target_context' => ['content_item_id' => 123],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'marketing-e2e-approval-required',
            correlationId: $request->correlationId,
        );
    });

    $result = marketingEndToEndService($provider)->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Mark the campaign content publication-ready.',
        expertSlugs: [],
        correlationId: 'marketing-e2e-approval-required',
    ));

    $execution = $result->execution->refresh();
    $approval = ApprovalRequest::query()->latest('id')->firstOrFail();

    expect($execution->status)->toBe(AgentExecution::STATUS_WAITING_FOR_APPROVAL)
        ->and($approval->status)->toBe(ApprovalRequest::STATUS_PENDING)
        ->and($approval->agent_execution_id)->toBe($execution->getKey())
        ->and(AgentDecision::query()->where('execution_id', $execution->getKey())->exists())->toBeTrue();
});

it('records a deterministic Marketing Agent provider failure as auditable execution state', function (): void {
    $fixture = marketingEndToEndFixture();
    extract($fixture);

    $provider = new FakeModelProvider(fn () => throw new ModelProviderException(
        ModelProviderFailureType::Unavailable,
        'fake',
        'provider unavailable',
    ));

    expect(fn () => marketingEndToEndService($provider)->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Fail safely.',
        expertSlugs: [],
        correlationId: 'marketing-e2e-failure',
    )))->toThrow(ModelProviderException::class);

    $execution = AgentExecution::query()->latest('id')->firstOrFail();

    expect($execution->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and($execution->failure_code)->toBe('provider.unavailable')
        ->and($execution->failure_reason)->toBe('The model provider could not complete the execution.')
        ->and($execution->completed_at)->not->toBeNull()
        ->and($execution->correlation_id)->toBe('marketing-e2e-failure')
        ->and($execution->agent_slug)->toBe('marketing')
        ->and(AgentDecision::query()->where('execution_id', $execution->getKey())->exists())->toBeFalse();
});

it('denies Marketing execution across organization and Enterprise boundaries', function (): void {
    $fixture = marketingEndToEndFixture();
    extract($fixture);

    $otherOrganization = \App\Models\Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create([
        'organization_id' => $otherOrganization->getKey(),
    ]);

    $authorizer = app(AgentCapabilityAuthorizer::class);
    $expert = app(\App\Experts\MarketingExpert::class);

    expect($authorizer->allowsExpertCapability(
        $assignment,
        'marketing',
        $expert,
        'marketing.plan',
        $otherOrganization,
        $enterprise,
        $actor,
    ))->toBeFalse()
        ->and($authorizer->allowsExpertCapability(
            $assignment,
            'marketing',
            $expert,
            'marketing.plan',
            $enterprise->organization,
            $foreignEnterprise,
            $actor,
        ))->toBeFalse();

    $execution = AgentExecution::factory()->forAssignment($assignment)->create();

    expect(fn () => app(CapabilityRegistry::class)->operation('marketing.plan')->execute($actor, [
        'enterprise' => $foreignEnterprise,
        'target_context' => ['enterprise_id' => $foreignEnterprise->getKey()],
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
    ]))->toThrow(AuthorizationException::class);
});