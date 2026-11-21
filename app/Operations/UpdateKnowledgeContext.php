<?php

namespace App\Operations;

final class UpdateKnowledgeContext extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
