<?php

namespace App\Operations;

final class ListKnowledgeContexts extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
