<?php

namespace App\Operations;

final class UpdateKnowledgeSource extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
