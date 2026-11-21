<?php

namespace App\Operations;

final class CreateKnowledgeContext extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'context';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_context_id';
    }
}
