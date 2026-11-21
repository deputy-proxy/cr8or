<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeReferences;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-references')]
#[Description('List Enterprise-scoped Knowledge Reference through the governed Knowledge capability.')]
final class ListKnowledgeReferencesTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeReferences::class;
    }

    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
