<?php

namespace App\Operations;

final class UpdateKnowledgeSpecification extends KnowledgeCatalogOperation
{
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
