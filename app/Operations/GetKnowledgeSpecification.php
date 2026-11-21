<?php

namespace App\Operations;

final class GetKnowledgeSpecification extends KnowledgeCatalogOperation
{
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
