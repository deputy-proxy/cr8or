<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeSource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-source')]
#[Description('Update Enterprise-scoped Knowledge Source through the governed Knowledge capability.')]
final class UpdateKnowledgeSourceTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeSource::class;
    }

    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
