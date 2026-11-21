<?php

namespace App\Operations;

final class CreateKnowledgeSpecification extends KnowledgeCatalogOperation
{
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
