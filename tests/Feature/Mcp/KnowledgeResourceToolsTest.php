<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ArchiveKnowledgeUnitTool;
use App\Mcp\Tools\CreateKnowledgeIndexTool;
use App\Mcp\Tools\CreateKnowledgeUnitTool;
use App\Mcp\Tools\GetKnowledgeIndexTool;
use App\Mcp\Tools\GetKnowledgeUnitTool;
use App\Mcp\Tools\ListKnowledgeIndexesTool;
use App\Mcp\Tools\ListKnowledgeUnitsTool;
use App\Mcp\Tools\UpdateKnowledgeIndexTool;
use App\Mcp\Tools\UpdateKnowledgeUnitTool;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

function knowledgeMcpActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'content' => 'Approval threshold is 1000 EUR.',
    ]);

    return [$user, $enterprise, $item];
}

it('registers the Knowledge resource MCP surface', function (): void {
    [$user] = knowledgeMcpActor();

    Cr8orServer::actingAs($user, 'api')
        ->tools()
        ->assertRegistered([
            CreateKnowledgeIndexTool::class,
            GetKnowledgeIndexTool::class,
            ListKnowledgeIndexesTool::class,
            UpdateKnowledgeIndexTool::class,
            CreateKnowledgeUnitTool::class,
            GetKnowledgeUnitTool::class,
            ListKnowledgeUnitsTool::class,
            UpdateKnowledgeUnitTool::class,
            ArchiveKnowledgeUnitTool::class,
        ]);
});

it('supports the complete Knowledge resource lifecycle through MCP', function (): void {
    [$user, $enterprise, $item] = knowledgeMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(CreateKnowledgeIndexTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
    ])->assertOk()->assertSee('indexed_units');

    $index = KnowledgeIndexRecord::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();
    $unit = KnowledgeIndexUnit::query()->where('knowledge_item_id', $item->getKey())->firstOrFail();

    $server->tool(GetKnowledgeIndexTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_index_id' => $index->getKey(),
    ])->assertOk()->assertSee((string) $item->getKey());

    $server->tool(ListKnowledgeIndexesTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 50,
    ])->assertOk()->assertSee((string) $index->getKey());

    $server->tool(GetKnowledgeUnitTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_unit_id' => $unit->getKey(),
    ])->assertOk()->assertSee('Approval threshold');

    $server->tool(ListKnowledgeUnitsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 50,
    ])->assertOk()->assertSee((string) $unit->getKey());

    $server->tool(UpdateKnowledgeIndexTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_index_id' => $index->getKey(),
    ])->assertOk();

    $server->tool(UpdateKnowledgeUnitTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_unit_id' => $unit->getKey(),
    ])->assertOk();

    $server->tool(ArchiveKnowledgeUnitTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_unit_id' => $unit->getKey(),
        'reason' => 'test archive',
    ])->assertOk()->assertSee('removed');
});

it('supports creating a specific Knowledge Unit through MCP', function (): void {
    [$user, $enterprise, $item] = knowledgeMcpActor();

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateKnowledgeUnitTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $item->getKey(),
            'unit_key' => 'chunk-0001',
        ])
        ->assertOk()
        ->assertSee('Approval threshold');
});

it('rejects cross-enterprise Knowledge resource access through MCP', function (): void {
    [$user, $enterprise] = knowledgeMcpActor();
    $foreign = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreign]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateKnowledgeIndexTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $foreignItem->getKey(),
        ])
        ->assertHasErrors();

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListKnowledgeIndexesTool::class, [
            'enterprise_id' => $foreign->getKey(),
        ])
        ->assertHasErrors();
});