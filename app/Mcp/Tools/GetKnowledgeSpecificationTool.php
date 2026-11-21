<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeSpecification;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-specification')]
#[Description('Get Enterprise-scoped Knowledge Specification through the governed Knowledge capability.')]
final class GetKnowledgeSpecificationTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeSpecification::class;
    }

    protected function resource(): string
    {
        return 'specification';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_specification_id';
    }
}
