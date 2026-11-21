<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeSource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-source')]
#[Description('Create Enterprise-scoped Knowledge Source through the governed Knowledge capability.')]
final class CreateKnowledgeSourceTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeSource::class;
    }

    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
