<?php

namespace App\Operations;

final class GetKnowledgeSource extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'source';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_source_id';
    }
}
