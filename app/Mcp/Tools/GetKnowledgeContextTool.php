<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeContext;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-context')]
#[Description('Get Enterprise-scoped Knowledge Context through the governed Knowledge capability.')]
final class GetKnowledgeContextTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeContext::class;
    }

    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
