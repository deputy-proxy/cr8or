<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeReference;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-reference')]
#[Description('Update Enterprise-scoped Knowledge Reference through the governed Knowledge capability.')]
final class UpdateKnowledgeReferenceTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeReference::class;
    }

    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
