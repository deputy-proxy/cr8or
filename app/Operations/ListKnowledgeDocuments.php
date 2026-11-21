<?php

namespace App\Operations;

final class ListKnowledgeDocuments extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'document';
    }

    protected function action(): string
    {
        return 'list';
    }

    protected function idField(): string
    {
        return 'knowledge_document_id';
    }
}
