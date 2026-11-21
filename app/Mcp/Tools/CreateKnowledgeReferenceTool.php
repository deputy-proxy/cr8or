<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeReference;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-reference')]
#[Description('Create Enterprise-scoped Knowledge Reference through the governed Knowledge capability.')]
final class CreateKnowledgeReferenceTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeReference::class;
    }

    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
