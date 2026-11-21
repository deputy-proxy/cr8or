<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateKnowledgeReferenceTool;
use App\Mcp\Tools\CreateKnowledgeSpecificationTool;
use App\Mcp\Tools\GetKnowledgeReferenceTool;
use App\Mcp\Tools\GetKnowledgeSpecificationTool;
use App\Mcp\Tools\ListKnowledgeReferencesTool;
use App\Mcp\Tools\ListKnowledgeSpecificationsTool;
use App\Mcp\Tools\UpdateKnowledgeReferenceTool;
use App\Mcp\Tools\UpdateKnowledgeSpecificationTool;
use App\Models\Enterprise;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeSpecification;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

function knowledgeSpecificationReferenceActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->getKey(), 'organization_id' => $organization->getKey()]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    return [$user, $enterprise];
}

it('registers all Knowledge Specification and Reference lifecycle tools', function (): void {
    [$user] = knowledgeSpecificationReferenceActor();

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        CreateKnowledgeSpecificationTool::class, GetKnowledgeSpecificationTool::class,
        ListKnowledgeSpecificationsTool::class, UpdateKnowledgeSpecificationTool::class,
        CreateKnowledgeReferenceTool::class, GetKnowledgeReferenceTool::class,
        ListKnowledgeReferencesTool::class, UpdateKnowledgeReferenceTool::class,
    ]);
});

it('supports Knowledge Specification and Reference CRUD through governed capabilities', function (): void {
    [$user, $enterprise] = knowledgeSpecificationReferenceActor();
    $server = Cr8orServer::actingAs($user, 'api');
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $document = KnowledgeDocument::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    $server->tool(CreateKnowledgeSpecificationTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(),
        'name' => 'Retention requirements', 'version' => '1.0', 'content' => 'Retain records for no more than five years.',
    ])->assertOk()->assertSee('Retention requirements');
    $specification = KnowledgeSpecification::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    $server->tool(CreateKnowledgeReferenceTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(),
        'knowledge_document_id' => $document->getKey(), 'type' => 'policy', 'label' => 'Retention policy',
        'locator' => 'section-4.2', 'metadata' => ['source' => 'internal-policy'],
    ])->assertOk()->assertSee('section-4.2');
    $reference = KnowledgeReference::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    $server->tool(GetKnowledgeSpecificationTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_specification_id' => $specification->getKey()])->assertOk()->assertSee('five years');
    $server->tool(GetKnowledgeReferenceTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_reference_id' => $reference->getKey()])->assertOk()->assertSee('internal-policy');
    $server->tool(ListKnowledgeSpecificationsTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(), 'version' => '1.0'])->assertOk()->assertSee('Retention requirements');
    $server->tool(ListKnowledgeReferencesTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $item->getKey(), 'knowledge_document_id' => $document->getKey(), 'type' => 'policy'])->assertOk()->assertSee('Retention policy');

    $server->tool(UpdateKnowledgeSpecificationTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_specification_id' => $specification->getKey(),
        'version' => '1.1', 'content' => 'Retain records for no more than three years.',
    ])->assertOk();
    $server->tool(UpdateKnowledgeReferenceTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_reference_id' => $reference->getKey(),
        'locator' => 'section-5.1', 'metadata' => ['source' => 'updated-policy'],
    ])->assertOk();

    expect($specification->fresh()->version)->toBe('1.1')
        ->and($specification->fresh()->content)->toContain('three years')
        ->and($reference->fresh()->locator)->toBe('section-5.1')
        ->and($reference->fresh()->metadata)->toBe(['source' => 'updated-policy']);
});

it('rejects cross-Enterprise Knowledge Item and Document relationships', function (): void {
    [$user, $enterprise] = knowledgeSpecificationReferenceActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $foreignDocument = KnowledgeDocument::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(CreateKnowledgeSpecificationTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $foreignItem->getKey(),
        'name' => 'Cross-tenant specification', 'content' => 'Must be rejected.',
    ])->assertHasErrors();

    $server->tool(CreateKnowledgeReferenceTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $foreignItem->getKey(),
        'knowledge_document_id' => $foreignDocument->getKey(), 'type' => 'source',
    ])->assertHasErrors();

    $ownItem = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $server->tool(CreateKnowledgeReferenceTool::class, [
        'enterprise_id' => $enterprise->getKey(), 'knowledge_item_id' => $ownItem->getKey(),
        'knowledge_document_id' => $foreignDocument->getKey(), 'type' => 'source',
    ])->assertHasErrors();

    expect(KnowledgeSpecification::query()->where('name', 'Cross-tenant specification')->exists())->toBeFalse()
        ->and(KnowledgeReference::query()->exists())->toBeFalse();
});

it('rejects cross-Enterprise record reads and list access', function (): void {
    [$user, $enterprise] = knowledgeSpecificationReferenceActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignItem = KnowledgeItem::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $foreignSpecification = KnowledgeSpecification::factory()->create(['enterprise_id' => $foreignEnterprise->getKey(), 'knowledge_item_id' => $foreignItem->getKey()]);
    $foreignReference = KnowledgeReference::factory()->create([
        'enterprise_id' => $foreignEnterprise->getKey(),
        'knowledge_item_id' => $foreignItem->getKey(),
        'knowledge_document_id' => null,
    ]);
    $server = Cr8orServer::actingAs($user, 'api');

    $server->tool(GetKnowledgeSpecificationTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_specification_id' => $foreignSpecification->getKey()])->assertHasErrors();
    $server->tool(GetKnowledgeReferenceTool::class, ['enterprise_id' => $enterprise->getKey(), 'knowledge_reference_id' => $foreignReference->getKey()])->assertHasErrors();
    $server->tool(ListKnowledgeSpecificationsTool::class, ['enterprise_id' => $foreignEnterprise->getKey()])->assertHasErrors();
    $server->tool(ListKnowledgeReferencesTool::class, ['enterprise_id' => $foreignEnterprise->getKey()])->assertHasErrors();
});
