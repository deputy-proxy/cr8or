<?php

namespace App\Operations;

final class CreateKnowledgeDocument extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'document';
    }

    protected function action(): string
    {
        return 'create';
    }

    protected function idField(): string
    {
        return 'knowledge_document_id';
    }
}
