<?php

use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
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

it('completes a governed Marketing Agent execution through the full strategy hierarchy', function (): void {
    $fixture = marketingEndToEndFixture();
    extract($fixture);

    $phase = 0;
    $strategyId = null;
    $campaignId = null;

    $provider = new FakeModelProvider(function ($request) use ($enterprise, &$phase, &$strategyId, &$campaignId): ModelResult {
        $phase++;

        $capabilityRequests = match ($phase) {
            1 => [
                json_encode([
                    'capability' => 'marketing.strategy.create',
                    'expert_slug' => 'marketing',
                    'target_context' => ['enterprise_id' => $enterprise->getKey()],
                    'input_payload' => [
                        'enterprise_id' => $enterprise->getKey(),
                        'name' => 'Marketing strategy',
                        'description' => 'Strategy created through the Marketing Expert route.',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            2 => [
                json_encode([
                    'capability' => 'marketing.audience.create',
                    'expert_slug' => 'marketing',
                    'target_context' => ['enterprise_id' => $enterprise->getKey()],
                    'input_payload' => ['enterprise_id' => $enterprise->getKey(), 'name' => 'Primary audience'],
                ], JSON_THROW_ON_ERROR),
                json_encode([
                    'capability' => 'marketing.campaign.create',
                    'expert_slug' => 'marketing',
                    'target_context' => ['enterprise_id' => $enterprise->getKey(), 'marketing_strategy_id' => $strategyId],
                    'input_payload' => [
                        'enterprise_id' => $enterprise->getKey(),
                        'marketing_strategy_id' => $strategyId,
                        'name' => 'Launch campaign',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            3 => [
                json_encode([
                    'capability' => 'marketing.content-series.create',
                    'expert_slug' => 'marketing',
                    'target_context' => ['campaign_id' => $campaignId],
                    'input_payload' => ['campaign_id' => $campaignId, 'name' => 'Launch content'],
                ], JSON_THROW_ON_ERROR),
            ],
            default => [],
        };

        return new ModelResult(
            text: 'Marketing hierarchy phase '.$phase.'.',
            structured: [
                'answer' => 'Marketing hierarchy phase '.$phase.'.',
                'decision_title' => 'Marketing hierarchy',
                'decision_summary' => 'The authorized enterprise context supports the requested hierarchy step.',
                'decision_rationale' => 'Marketing Agent and Expert reasoning stayed within the assigned Enterprise.',
                'capability_requests' => $capabilityRequests,
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'marketing-hierarchy-'.$phase,
            correlationId: $request->correlationId,
        );
    });

    $first = marketingEndToEndService($provider)->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create the marketing strategy.',
        expertSlugs: ['marketing'],
        correlationId: 'marketing-hierarchy-1',
    ));

    $strategyRequest = $first->capabilityRequests[0];
    $strategyResult = app(CapabilityRegistry::class)
        ->operation($strategyRequest->capability)
        ->execute($actor, [
            ...$strategyRequest->inputPayload,
            'enterprise' => $enterprise,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $first->execution->getKey(),
        ]);
    $strategyId = $strategyResult->getKey();

    $second = marketingEndToEndService($provider)->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create the audience and campaign from the strategy result.',
        expertSlugs: ['marketing'],
        correlationId: 'marketing-hierarchy-2',
    ));

    $audienceRequest = $second->capabilityRequests[0];
    $audienceResult = app(CapabilityRegistry::class)
        ->operation($audienceRequest->capability)
        ->execute($actor, [
            ...$audienceRequest->inputPayload,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $second->execution->getKey(),
        ]);

    $campaignRequest = $second->capabilityRequests[1];
    $campaignResult = app(CapabilityRegistry::class)
        ->operation($campaignRequest->capability)
        ->execute($actor, [
            ...$campaignRequest->inputPayload,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $second->execution->getKey(),
        ]);
    $campaignId = $campaignResult->getKey();

    $third = marketingEndToEndService($provider)->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create the content series from the campaign result.',
        expertSlugs: ['marketing'],
        correlationId: 'marketing-hierarchy-3',
    ));

    $seriesRequest = $third->capabilityRequests[0];
    $seriesResult = app(CapabilityRegistry::class)
        ->operation($seriesRequest->capability)
        ->execute($actor, [
            ...$seriesRequest->inputPayload,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $third->execution->getKey(),
        ]);

    expect($first->succeeded())->toBeTrue()
        ->and($second->succeeded())->toBeTrue()
        ->and($third->succeeded())->toBeTrue()
        ->and($strategyResult->enterprise_id)->toBe($enterprise->getKey())
        ->and($audienceResult->enterprise_id)->toBe($enterprise->getKey())
        ->and($campaignResult->marketing_strategy_id)->toBe($strategyId)
        ->and($seriesResult->campaign_id)->toBe($campaignId)
        ->and($phase)->toBe(3);
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