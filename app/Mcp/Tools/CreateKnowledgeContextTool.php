<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeContext;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-context')]
#[Description('Create Enterprise-scoped Knowledge Context through the governed Knowledge capability.')]
final class CreateKnowledgeContextTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeContext::class;
    }

    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
