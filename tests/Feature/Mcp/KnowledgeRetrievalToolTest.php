<?php

use App\Capabilities\CapabilityRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\RetrieveKnowledgeTool;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeIndexingService;

function retrievalMcpActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise, 'title' => 'Approval Policy']);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'content' => 'Approval threshold is 1000 EUR.',
    ]);

    app(KnowledgeIndexingService::class)->indexItem($user, $item);

    return [$user, $enterprise, $item];
}

it('registers knowledge.retrieve as a governed capability', function (): void {
    $definition = app(CapabilityRegistry::class)->resolve('knowledge.retrieve');

    expect($definition->operation)->toBe(App\Operations\RetrieveKnowledge::class)
        ->and($definition->toolClass)->toBe(RetrieveKnowledgeTool::class)
        ->and($definition->tool)->toBe('retrieve-knowledge');
});

it('retrieves bounded lexical Knowledge through MCP with provenance', function (): void {
    [$user, $enterprise, $item] = retrievalMcpActor();

    Cr8orServer::actingAs($user, 'api')
        ->tool(RetrieveKnowledgeTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'query' => 'approval threshold',
            'mode' => 'lexical',
            'limit' => 5,
        ])
        ->assertOk()
        ->assertSee(['Approval Policy', 'provenance_normalized']);
});

it('rejects cross-enterprise Knowledge retrieval through MCP', function (): void {
    [$user] = retrievalMcpActor();
    $foreign = Enterprise::factory()->create();

    Cr8orServer::actingAs($user, 'api')
        ->tool(RetrieveKnowledgeTool::class, [
            'enterprise_id' => $foreign->getKey(),
            'query' => 'secret',
            'mode' => 'lexical',
        ])
        ->assertHasErrors();
});