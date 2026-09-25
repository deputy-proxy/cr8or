<?php

use App\Agents\Agent;
use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentPermission;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentExecutionService;
use App\Services\ContentGenerationService;
use App\Services\ContentItemService;
use App\Services\McpContextAssembler;

function contentAgentRuntimeClass(): string
{
    return get_class(new class extends Agent
    {
        public function name(): string
        {
            return 'Content Agent';
        }

        public function description(): string
        {
            return 'Creates governed enterprise content.';
        }

        public function responsibilities(): array
        {
            return ['content'];
        }

        public function capabilities(): array
        {
            return ['marketing.content.create', 'marketing.content.update'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }
    });
}

it('keeps AI-generated content as a draft with execution and decision provenance', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor, 'organization_id' => $enterprise->organization_id]);
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(contentAgentRuntimeClass())->create(['slug' => 'content-agent']);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor]);
    AgentPermission::factory()->create(['agent_assignment_id' => $assignment, 'capability' => 'marketing.content.create']);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise,
        'marketing_strategy_id' => MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]),
    ]);

    $provider = new FakeModelProvider(fn ($request) => new ModelResult(
        text: 'AI draft body',
        structured: [
            'answer' => 'AI draft body',
            'decision_title' => 'Draft content',
            'decision_summary' => 'Created a draft.',
            'decision_rationale' => 'Generated from authorized context.',
            'capability_requests' => [],
        ],
        provider: 'fake',
        model: 'test',
        invocationId: 'content-fake-1',
        correlationId: $request->correlationId,
    ));

    $executions = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(App\Services\AgentCapabilityAuthorizer::class),
    );

    $item = (new ContentGenerationService(
        $executions,
        app(App\Services\AgentCapabilityAuthorizer::class),
        app(App\Capabilities\CapabilityRegistry::class),
    ))->generate(
        $actor,
        $assignment,
        $enterprise,
        'Create a social post draft.',
        ['campaign_id' => $campaign->id, 'title' => 'Generated post'],
    );

    expect($item->status)->toBe(ContentItem::STATUS_DRAFT)
        ->and($item->body)->toBe('AI draft body')
        ->and($item->agent_execution_id)->not->toBeNull()
        ->and($item->agent_decision_id)->not->toBeNull()
        ->and($item->agentExecution->getKey())->toBe($item->agent_execution_id)
        ->and($item->agentDecision->getKey())->toBe($item->agent_decision_id);
});

it('rejects AI revision of approved content', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor, 'organization_id' => $enterprise->organization_id]);
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(contentAgentRuntimeClass())->create(['slug' => 'content-agent']);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor]);
    AgentPermission::factory()->create(['agent_assignment_id' => $assignment, 'capability' => 'marketing.content.update']);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise,
        'marketing_strategy_id' => MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]),
    ]);
    $item = ContentItem::factory()->forCampaign($campaign)->create(['status' => ContentItem::STATUS_APPROVED]);

    expect(fn () => app(ContentItemService::class)->update($actor, $item, ['body' => 'Changed']))
        ->toThrow(LogicException::class);
});

it('does not create content when the governed Agent provider fails', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor, 'organization_id' => $enterprise->organization_id]);
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(contentAgentRuntimeClass())->create(['slug' => 'content-failure-agent']);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor]);
    AgentPermission::factory()->create(['agent_assignment_id' => $assignment, 'capability' => 'marketing.content.create']);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise,
        'marketing_strategy_id' => MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]),
    ]);
    $before = ContentItem::query()->count();
    $provider = new FakeModelProvider(fn () => throw new ModelProviderException(
        ModelProviderFailureType::Unavailable,
        'fake',
        'provider unavailable',
    ));
    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(App\Services\AgentCapabilityAuthorizer::class),
    );

    expect(fn () => app(ContentGenerationService::class)->generate(
        $actor, $assignment, $enterprise, 'Generate content safely.', ['campaign_id' => $campaign->id, 'title' => 'Should not persist'],
    ))->toThrow(ModelProviderException::class);

    expect(ContentItem::query()->count())->toBe($before);
});