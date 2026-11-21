<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeSpecification;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-specification')]
#[Description('Update Enterprise-scoped Knowledge Specification through the governed Knowledge capability.')]
final class UpdateKnowledgeSpecificationTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeSpecification::class;
    }

    protected function resource(): string
    {
        return 'specification';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_specification_id';
    }
}
