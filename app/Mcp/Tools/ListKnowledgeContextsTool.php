<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeContexts;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-contexts')]
#[Description('List Enterprise-scoped Knowledge Contexts through the governed Knowledge capability.')]
final class ListKnowledgeContextsTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeContexts::class;
    }

    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
