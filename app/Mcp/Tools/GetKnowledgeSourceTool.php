<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeSource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-source')]
#[Description('Get Enterprise-scoped Knowledge Source through the governed Knowledge capability.')]
final class GetKnowledgeSourceTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeSource::class;
    }

    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
