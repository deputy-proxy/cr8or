<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeContext;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-context')]
#[Description('Update Enterprise-scoped Knowledge Context through the governed Knowledge capability.')]
final class UpdateKnowledgeContextTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeContext::class;
    }

    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
