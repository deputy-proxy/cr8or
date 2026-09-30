<?php

use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateScriptTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use LogicException;

beforeEach(function (): void {
    $this->seed([
        Database\Seeders\AgentDescriptorSeeder::class,
        Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

function marketingScriptFixture(bool $foreignContent = false): array
{
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $contentEnterprise = $foreignContent ? Enterprise::factory()->create(['organization_id' => $organization->getKey()]) : $enterprise;
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $contentEnterprise->getKey()]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $contentEnterprise->getKey(),
        'marketing_strategy_id' => $strategy->getKey(),
    ]);
    $item = ContentItem::factory()->forCampaign($campaign)->create();
    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey()]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'actor_id' => $actor->getKey(),
        'actor_name' => $actor->name,
    ]);

    return compact('actor', 'enterprise', 'contentEnterprise', 'item', 'assignment', 'execution');
}

it('declares marketing.script.create on the Copywriting Expert without publication authority', function (): void {
    $definition = app(App\Experts\CopywritingExpert::class)->definition();

    expect($definition->capabilities)
        ->toContain('marketing.script.create')
        ->and($definition->capabilities)->not->toContain('publication.publish');

    expect(app(CapabilityRegistry::class)->forTool(CreateScriptTool::class)->key)
        ->toBe('marketing.script.create');
});

it('creates a script with immutable Agent assignment and execution provenance', function (): void {
    $fixture = marketingScriptFixture();
    extract($fixture);

    $script = app(App\Operations\CreateScript::class)->execute($actor, [
        'content_item' => $item,
        'content_item_id' => $item->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'title' => 'Launch script',
        'body' => 'Draft the launch narrative.',
    ]);

    expect($script->content_item_id)->toBe($item->getKey())
        ->and($script->agent_assignment_id)->toBe($assignment->getKey())
        ->and($script->agent_execution_id)->toBe($execution->getKey())
        ->and($script->title)->toBe('Launch script');

    expect(fn () => $script->update(['agent_execution_id' => $execution->getKey() + 1]))
        ->toThrow(LogicException::class);
});

it('fails closed when script provenance crosses enterprise boundaries', function (): void {
    $fixture = marketingScriptFixture(foreignContent: true);
    extract($fixture);

    expect(fn () => app(App\Operations\CreateScript::class)->execute($actor, [
        'content_item' => $item,
        'content_item_id' => $item->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'title' => 'Must not persist',
        'body' => 'Unauthorized cross-enterprise script.',
    ]))->toThrow(LogicException::class);

    expect(Script::query()->where('content_item_id', $item->getKey())->exists())->toBeFalse();
});

it('executes script creation through a Marketing Agent and the Copywriting Expert route', function (): void {
    $fixture = marketingScriptFixture();
    extract($fixture);

    $provider = new FakeModelProvider(function ($request) use ($item): ModelResult {
        return new ModelResult(
            text: 'Script requested.',
            structured: [
                'answer' => 'Script requested.',
                'decision_title' => 'Script creation',
                'decision_summary' => 'Create a script for the authorized ContentItem.',
                'decision_rationale' => 'Copywriting owns script creation and publication remains separate.',
                'capability_requests' => [
                    json_encode([
                        'capability' => 'marketing.script.create',
                        'expert_slug' => 'copywriting',
                        'target_context' => ['content_item_id' => $item->getKey()],
                        'input_payload' => [
                            'content_item_id' => $item->getKey(),
                            'title' => 'Agent generated script',
                            'body' => 'Agent-generated draft script.',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'script-e2e',
            correlationId: $request->correlationId,
        );
    });

    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    );

    $result = $service->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Create the campaign script.',
        expertSlugs: ['copywriting'],
        correlationId: 'script-e2e',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->capabilityRequests)->toHaveCount(1)
        ->and($result->capabilityRequests[0]->capability)->toBe('marketing.script.create')
        ->and($result->capabilityRequests[0]->expertSlug)->toBe('copywriting');

    $request = $result->capabilityRequests[0];
    $script = app(CapabilityRegistry::class)->operation($request->capability)->execute($actor, [
        ...$request->inputPayload,
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $result->execution->getKey(),
    ]);

    expect($script)->toBeInstanceOf(Script::class)
        ->and($script->content_item_id)->toBe($item->getKey())
        ->and($script->agent_assignment_id)->toBe($assignment->getKey())
        ->and($script->agent_execution_id)->toBe($result->execution->getKey());
});

it('serves create-script through the MCP governance boundary', function (): void {
    $fixture = marketingScriptFixture();
    extract($fixture);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateScriptTool::class, [
            'content_item_id' => $item->getKey(),
            'title' => 'MCP script',
            'body' => 'Created through the governed MCP boundary.',
        ])
        ->assertOk();

    $script = Script::query()->latest('id')->firstOrFail();

    expect($script->content_item_id)->toBe($item->getKey())
        ->and($script->agent_assignment_id)->toBeNull()
        ->and($script->agent_execution_id)->toBeNull();
});

it('rejects MCP script creation for an enterprise outside the actor organization', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);
    $item = ContentItem::factory()->forCampaign($campaign)->create();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateScriptTool::class, [
            'content_item_id' => $item->getKey(),
            'title' => 'Must not persist',
            'body' => 'Cross-organization script.',
        ])
        ->assertHasErrors();

    expect(Script::query()->where('content_item_id', $item->getKey())->exists())->toBeFalse();
});