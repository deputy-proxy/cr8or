<?php

use App\Capabilities\CapabilityRegistry;
use App\Experts\ExpertRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateKnowledgeIndexTool;
use App\Mcp\Tools\GetKnowledgeItemTool;
use App\Mcp\Tools\ListCapabilitiesTool;
use App\Mcp\Tools\ListKnowledgeItemsTool;
use App\Mcp\Tools\UpdateKnowledgeItemTool;
use App\Models\Enterprise;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Operations\GetKnowledgeItem;
use App\Operations\ListKnowledgeItems;
use App\Operations\UpdateKnowledgeItem;

function knowledgeItemMcpActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise,
        'title' => 'Approval policy',
        'type' => 'policy',
        'summary' => 'Approval limits for operating spend.',
    ]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
        'version' => 1,
        'content' => 'Expenses above 1000 EUR require director approval.',
    ]);

    return [$user, $enterprise, $item];
}

it('registers governed Knowledge Item tools and exposes them through the Knowledge Expert', function (): void {
    [$user] = knowledgeItemMcpActor();
    app(\Database\Seeders\ExpertDescriptorSeeder::class)->run();

    Cr8orServer::actingAs($user, 'api')
        ->tools()
        ->assertRegistered([
            GetKnowledgeItemTool::class,
            ListKnowledgeItemsTool::class,
            UpdateKnowledgeItemTool::class,
        ]);

    $registry = app(CapabilityRegistry::class);

    expect($registry->forTool(GetKnowledgeItemTool::class)->key)->toBe('knowledge.item.get')
        ->and($registry->operationForTool(GetKnowledgeItemTool::class))->toBeInstanceOf(GetKnowledgeItem::class)
        ->and($registry->forTool(ListKnowledgeItemsTool::class)->key)->toBe('knowledge.item.list')
        ->and($registry->operationForTool(ListKnowledgeItemsTool::class))->toBeInstanceOf(ListKnowledgeItems::class)
        ->and($registry->forTool(UpdateKnowledgeItemTool::class)->key)->toBe('knowledge.item.update')
        ->and($registry->operationForTool(UpdateKnowledgeItemTool::class))->toBeInstanceOf(UpdateKnowledgeItem::class)
        ->and(app(ExpertRegistry::class)->resolve('knowledge')->capabilities())->toContain(
            'knowledge.item.create',
            'knowledge.item.get',
            'knowledge.item.list',
            'knowledge.item.update',
        );

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListCapabilitiesTool::class, ['search' => 'knowledge.item'])
        ->assertOk()
        ->assertSee('knowledge.item.get')
        ->assertSee('knowledge.item.list')
        ->assertSee('knowledge.item.update');
});

it('gets, lists and updates authoritative Knowledge Items through governed MCP tools', function (): void {
    [$user, $enterprise, $item] = knowledgeItemMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(GetKnowledgeItemTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
    ])->assertOk()
        ->assertSee('Approval policy')
        ->assertSee('Expenses above 1000 EUR');

    $server->tool(ListKnowledgeItemsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'search' => 'operating spend',
        'type' => 'policy',
        'per_page' => 10,
        'page' => 1,
    ])->assertOk()
        ->assertSee('Approval policy')
        ->assertSee('"total":1');

    $server->tool(CreateKnowledgeIndexTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
    ])->assertOk();

    $previousVersion = $item->versions()->where('version', 1)->firstOrFail();
    $server->tool(UpdateKnowledgeItemTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'title' => 'Updated approval policy',
        'content' => 'Expenses above 1500 EUR require director approval.',
        'context_snapshot' => ['source' => 'approved policy update'],
    ])->assertOk()
        ->assertSee('Updated approval policy')
        ->assertSee('Expenses above 1500 EUR');

    $item->refresh();
    $versions = $item->versions()->orderBy('version')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->is($previousVersion))->toBeTrue()
        ->and($versions[0]->content)->toBe('Expenses above 1000 EUR require director approval.')
        ->and($versions[1]->version)->toBe(2)
        ->and($versions[1]->content)->toBe('Expenses above 1500 EUR require director approval.')
        ->and(KnowledgeIndexRecord::query()->where('knowledge_item_id', $item->getKey())->where('status', 'stale')->exists())->toBeTrue();
});

it('prevents Knowledge Item MCP access across Enterprise boundaries', function (): void {
    [$user, $enterprise] = knowledgeItemMcpActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreignEnterprise]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetKnowledgeItemTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $foreignItem->getKey(),
        ])->assertHasErrors();

    Cr8orServer::actingAs($user, 'api')
        ->tool(UpdateKnowledgeItemTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $foreignItem->getKey(),
            'title' => 'Unauthorized change',
        ])->assertHasErrors();

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListKnowledgeItemsTool::class, [
            'enterprise_id' => $foreignEnterprise->getKey(),
        ])->assertHasErrors();

    expect($foreignItem->fresh()->title)->not->toBe('Unauthorized change');
});

it('versions Knowledge Item context snapshot changes without changing the content', function (): void {
    [$user, $enterprise, $item] = knowledgeItemMcpActor();

    Cr8orServer::actingAs($user, 'api')
        ->tool(UpdateKnowledgeItemTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $item->getKey(),
            'context_snapshot' => ['source' => 'updated context'],
        ])->assertOk();

    $versions = $item->versions()->orderBy('version')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[1]->version)->toBe(2)
        ->and($versions[1]->content)->toBe($versions[0]->content)
        ->and($versions[1]->context_snapshot)->toBe(['source' => 'updated context']);
});

it('does not create a content version for metadata-only Knowledge Item updates', function (): void {
    [$user, $enterprise, $item] = knowledgeItemMcpActor();

    Cr8orServer::actingAs($user, 'api')
        ->tool(UpdateKnowledgeItemTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $item->getKey(),
            'summary' => 'Updated approval limits and review ownership.',
        ])->assertOk();

    expect($item->versions()->count())->toBe(1)
        ->and($item->fresh()->summary)->toBe('Updated approval limits and review ownership.');
});
