<?php

namespace App\Operations;

final class ListKnowledgeReferences extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'reference';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_reference_id';
    }
}
