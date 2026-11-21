<?php

namespace App\Operations;

final class CreateKnowledgeSource extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
