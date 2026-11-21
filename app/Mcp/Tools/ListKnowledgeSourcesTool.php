<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeSources;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-sources')]
#[Description('List Enterprise-scoped Knowledge Sources through the governed Knowledge capability.')]
final class ListKnowledgeSourcesTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeSources::class;
    }

    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
