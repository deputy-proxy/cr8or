<?php

use App\Agents\MarketingAgent;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
use App\Data\CapabilityRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use InvalidArgumentException;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

it('defines the production Marketing Agent runtime contract', function (): void {
    $agent = app(MarketingAgent::class);

    expect($agent->name())->toBe('Marketing')
        ->and($agent->experts())->toBe(['marketing', 'strategy', 'copywriting', 'seo'])
        ->and($agent->requiredContext())->toBe([
            'enterprise',
            'strategy',
            'work',
            'knowledge',
            'decisions',
            'execution_history',
        ])
        ->and($agent->capabilities())->toBe([
            'marketing.plan',
            'marketing.content.create',
            'marketing.content.update',
            'marketing.content.review',
            'marketing.content.publication-ready',
        ])
        ->and($agent->instructions())
        ->toContain('Request human approval whenever the selected Capability is approval-sensitive')
        ->and($agent->decisionBoundaries())->toHaveCount(8)
        ->and($agent->expectedOutputs())->toHaveCount(3)
        ->and($agent->capabilityMap())->toMatchArray([
            'plan marketing activity' => ['marketing.plan'],
            'coordinate marketing expertise' => ['marketing.plan', 'marketing.content.review'],
        ])
        ->and($agent->capabilityGaps())->toContain('marketing.campaign.performance-analysis')
        ->and($agent->approvalSensitiveCapabilities())->toBe(['marketing.content.publication-ready'])
        ->and($agent->expertRouting())->toMatchArray([
            'plan marketing activity' => ['marketing', 'strategy'],
            'coordinate marketing expertise' => ['marketing', 'strategy', 'copywriting', 'seo'],
            'coordinate campaign and content work' => ['copywriting', 'seo'],
            'protect content governance' => ['seo', 'copywriting'],
        ])
        ->and($agent->definitionVersion())->toMatch('/^[a-f0-9]{64}$/');

    $registry = app(CapabilityRegistry::class);

    foreach ($agent->capabilityMap() as $responsibility => $capabilities) {
        expect($agent->responsibilities())->toContain($responsibility);
        foreach ($capabilities as $capability) {
            expect($agent->capabilities())->toContain($capability)
                ->and(fn () => $registry->resolve($capability))->not->toThrow(Throwable::class);
        }
    }

    foreach ($agent->capabilityGaps() as $gap) {
        expect(fn () => $registry->resolve($gap))->toThrow(InvalidArgumentException::class);
    }
});

it('executes through the canonical Agent contract with authorized context, Expert coordination and a governed Capability request', function (): void {
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

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->getKey(),
        'capability' => 'marketing.plan',
    ]);

    $provider = new FakeModelProvider(function ($request) use ($enterprise): ModelResult {
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
            ->and($request->context['agent']['slug'])->toBe('marketing')
            ->and($request->context['experts']['results'][0]['expert'])->toBe('Marketing')
            ->and($request->context['experts']['results'][0]['result']['available_context'])
            ->toBe(['enterprise', 'strategy', 'knowledge', 'invocation']);

        return new ModelResult(
            text: 'Marketing plan prepared.',
            structured: [
                'answer' => 'Marketing plan prepared.',
                'decision_title' => 'Marketing plan',
                'decision_summary' => 'The authorized marketing context supports the requested plan.',
                'decision_rationale' => 'The Agent and Marketing Expert operated within the supplied Enterprise scope.',
                'capability_requests' => [
                    json_encode([
                        'capability' => 'marketing.plan',
                        'target_context' => ['enterprise_id' => $enterprise->getKey()],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'marketing-runtime-test',
            correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Prepare a governed marketing plan.',
        expertSlugs: ['marketing'],
        correlationId: 'marketing-runtime-contract',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(AgentExecution::STATUS_SUCCEEDED)
        ->and($result->execution->agent_slug)->toBe('marketing')
        ->and($result->execution->correlation_id)->toBe('marketing-runtime-contract')
        ->and($result->execution->agent_definition_version)->toBe(app(MarketingAgent::class)->definitionVersion())
        ->and($result->decision)->not->toBeNull()
        ->and($result->capabilityRequests)->toHaveCount(1)
        ->and($result->capabilityRequests[0])->toBeInstanceOf(CapabilityRequest::class)
        ->and($result->capabilityRequests[0]->capability)->toBe('marketing.plan')
        ->and($result->capabilityRequests[0]->execution->getKey())->toBe($result->execution->getKey());
});