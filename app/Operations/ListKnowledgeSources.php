<?php

namespace App\Operations;

final class ListKnowledgeSources extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
