<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateKnowledgeContextTool;
use App\Mcp\Tools\CreateKnowledgeDocumentTool;
use App\Mcp\Tools\CreateKnowledgeSourceTool;
use App\Mcp\Tools\GetKnowledgeContextTool;
use App\Mcp\Tools\GetKnowledgeDocumentTool;
use App\Mcp\Tools\GetKnowledgeSourceTool;
use App\Mcp\Tools\ListKnowledgeContextsTool;
use App\Mcp\Tools\ListKnowledgeDocumentsTool;
use App\Mcp\Tools\ListKnowledgeSourcesTool;
use App\Mcp\Tools\UpdateKnowledgeContextTool;
use App\Mcp\Tools\UpdateKnowledgeDocumentTool;
use App\Mcp\Tools\UpdateKnowledgeSourceTool;
use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeIndexRecord;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

function knowledgeCatalogActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->getKey(), 'organization_id' => $organization->getKey()]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    return [$user, $enterprise];
}

it('registers all Knowledge Source, Document and Context lifecycle tools', function (): void {
    [$user] = knowledgeCatalogActor();

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        CreateKnowledgeSourceTool::class, GetKnowledgeSourceTool::class, ListKnowledgeSourcesTool::class, UpdateKnowledgeSourceTool::class,
        CreateKnowledgeDocumentTool::class, GetKnowledgeDocumentTool::class, ListKnowledgeDocumentsTool::class, UpdateKnowledgeDocumentTool::class,
        CreateKnowledgeContextTool::class, GetKnowledgeContextTool::class, ListKnowledgeContextsTool::class, UpdateKnowledgeContextTool::class,
    ]);
});

it('supports source, document and context CRUD through governed MCP capabilities', function (): void {
    [$user, $enterprise] = knowledgeCatalogActor();
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(CreateKnowledgeSourceTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'name' => 'ChatGPT Conversation', 'type' => 'conversation',
        'description' => 'Original EdVenture discussion', 'uri' => 'https://chatgpt.com/c/example', 'metadata' => ['system' => 'chatgpt'],
    ])->assertOk()->assertSee('ChatGPT Conversation');
    $source = KnowledgeSource::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    $server->tool(CreateKnowledgeContextTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'name' => 'EdVenture Strategy', 'type' => 'project',
        'description' => 'Durable knowledge about EdVenture strategy', 'data' => ['project' => 'edventure.ro'],
    ])->assertOk()->assertSee('EdVenture Strategy');
    $context = KnowledgeContext::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    $server->tool(CreateKnowledgeDocumentTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey(),
        'title' => 'Conversation Synthesis', 'identifier' => 'edventure-chat-001', 'status' => 'active',
        'content' => 'Curated decisions, facts and open questions.', 'metadata' => ['capture' => 'conversation'],
    ])->assertOk()->assertSee('Conversation Synthesis');
    $document = KnowledgeDocument::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    $server->tool(GetKnowledgeSourceTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey()])->assertOk()->assertSee('conversation');
    $server->tool(GetKnowledgeDocumentTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_document_id' => $document->getKey()])->assertOk()->assertSee('Curated decisions');
    $server->tool(GetKnowledgeContextTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_context_id' => $context->getKey()])->assertOk()->assertSee('EdVenture Strategy');

    $server->tool(ListKnowledgeSourcesTool::class, ['enterprise_id' => $enterprise->getKey(), 'type' => 'conversation', 'per_page' => 10])->assertOk()->assertSee('ChatGPT Conversation');
    $server->tool(ListKnowledgeDocumentsTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey()])->assertOk()->assertSee('Conversation Synthesis');
    $server->tool(ListKnowledgeContextsTool::class, ['enterprise_id' => $enterprise->getKey(), 'search' => 'EdVenture'])->assertOk()->assertSee('EdVenture Strategy');

    $server->tool(UpdateKnowledgeSourceTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey(), 'description' => 'Updated source description'])->assertOk();
    $server->tool(UpdateKnowledgeDocumentTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_document_id' => $document->getKey(), 'status' => 'reviewed'])->assertOk();
    $server->tool(UpdateKnowledgeContextTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_context_id' => $context->getKey(), 'description' => 'Updated context description'])->assertOk();

    expect($source->fresh()->description)->toBe('Updated source description')
        ->and($document->fresh()->status)->toBe('reviewed')
        ->and($context->fresh()->description)->toBe('Updated context description');
});

it('rejects cross-Enterprise source relationships and record access', function (): void {
    [$user, $enterprise] = knowledgeCatalogActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignSource = KnowledgeSource::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(CreateKnowledgeDocumentTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $foreignSource->getKey(),
        'title' => 'Cross tenant document', 'status' => 'active', 'content' => 'Must not be persisted.',
    ])->assertHasErrors();

    $ownSource = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $ownDocument = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $ownSource->getKey(),
    ]);
    $server->tool(UpdateKnowledgeDocumentTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_document_id' => $ownDocument->getKey(),
        'knowledge_source_id' => $foreignSource->getKey(),
    ])->assertHasErrors();

    $server->tool(ListKnowledgeSourcesTool::class, ['enterprise_id' => $foreignEnterprise->getKey()])->assertHasErrors();

    expect(KnowledgeDocument::query()->where('title', 'Cross tenant document')->exists())->toBeFalse()
        ->and($ownDocument->fresh()->knowledge_source_id)->toBe($ownSource->getKey());
});

it('rejects updates without mutable fields', function (): void {
    [$user, $enterprise] = knowledgeCatalogActor();
    $source = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    Cr8orServer::actingAs($user, 'api')->tool(UpdateKnowledgeSourceTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey(),
    ])->assertHasErrors();
});

it('associates Knowledge Items with Enterprise-scoped source, document and context records', function (): void {
    [$user, $enterprise] = knowledgeCatalogActor();
    $server = Cr8orServer::actingAs($user, 'api');
    $source = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $context = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $document = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
    ]);

    $server->tool(\App\Mcp\Tools\CreateKnowledgeItemTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey(),
        'knowledge_document_id' => $document->getKey(), 'knowledge_context_id' => $context->getKey(),
        'title' => 'EdVenture enrollment policy', 'content' => 'Enrollment is confirmed after payment.',
    ])->assertOk()->assertSee('knowledge_context_id');
    $item = \App\Models\KnowledgeItem::query()->where('enterprise_id', $enterprise->getKey())->where('title', 'EdVenture enrollment policy')->firstOrFail();

    $server->tool(\App\Mcp\Tools\CreateKnowledgeIndexTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(),
    ])->assertOk();

    $server->tool(\App\Mcp\Tools\UpdateKnowledgeItemTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(),
        'knowledge_context_id' => null,
    ])->assertOk();

    expect($item->fresh()->knowledge_source_id)->toBe($source->getKey())
        ->and($item->fresh()->knowledge_document_id)->toBe($document->getKey())
        ->and($item->fresh()->knowledge_context_id)->toBeNull()
        ->and(KnowledgeIndexRecord::query()->where('knowledge_item_id', $item->getKey())->where('status', 'stale')->exists())->toBeTrue();
});

it('rejects Knowledge Item relationships from another Enterprise', function (): void {
    [$user, $enterprise] = knowledgeCatalogActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignContext = KnowledgeContext::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);

    Cr8orServer::actingAs($user, 'api')->tool(\App\Mcp\Tools\CreateKnowledgeItemTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_context_id' => $foreignContext->getKey(),
        'title' => 'Must not be linked', 'content' => 'Cross-Enterprise references are forbidden.',
    ])->assertHasErrors();

    expect(\App\Models\KnowledgeItem::query()->where('title', 'Must not be linked')->exists())->toBeFalse();
});
