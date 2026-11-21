<?php

namespace App\Operations;

final class ListKnowledgeSpecifications extends KnowledgeCatalogOperation
{
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
