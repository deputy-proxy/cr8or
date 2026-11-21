<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeSpecification;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-specification')]
#[Description('Create Enterprise-scoped Knowledge Specification through the governed Knowledge capability.')]
final class CreateKnowledgeSpecificationTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeSpecification::class;
    }

    protected function resource(): string
    {
        return 'specification';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_specification_id';
    }
}
