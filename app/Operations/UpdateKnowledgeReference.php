<?php

namespace App\Operations;

final class UpdateKnowledgeReference extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
