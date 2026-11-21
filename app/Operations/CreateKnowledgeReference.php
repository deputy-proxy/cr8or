<?php

namespace App\Operations;

final class CreateKnowledgeReference extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
