<?php

namespace App\Operations;

final class GetKnowledgeReference extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
