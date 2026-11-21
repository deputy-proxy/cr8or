<?php

namespace App\Operations;

final class GetKnowledgeContext extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
