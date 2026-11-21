<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeSpecifications;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-specifications')]
#[Description('List Enterprise-scoped Knowledge Specification through the governed Knowledge capability.')]
final class ListKnowledgeSpecificationsTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeSpecifications::class;
    }

    protected function resource(): string
    {
        return 'specification';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_specification_id';
    }
}
