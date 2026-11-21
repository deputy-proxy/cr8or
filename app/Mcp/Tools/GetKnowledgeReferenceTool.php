<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeReference;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-reference')]
#[Description('Get Enterprise-scoped Knowledge Reference through the governed Knowledge capability.')]
final class GetKnowledgeReferenceTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeReference::class;
    }

    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
