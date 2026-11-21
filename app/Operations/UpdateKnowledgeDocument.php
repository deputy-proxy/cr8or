<?php

namespace App\Operations;

final class UpdateKnowledgeDocument extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'document';
    }

    protected function action(): string
    {
        return 'update';
    }

    protected function idField(): string
    {
        return 'knowledge_document_id';
    }
}
