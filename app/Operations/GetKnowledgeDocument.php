<?php

namespace App\Operations;

final class GetKnowledgeDocument extends KnowledgeCatalogOperation
{
    protected function resource(): string
    {
        return 'document';
    }

    protected function action(): string
    {
        return 'get';
    }

    protected function idField(): string
    {
        return 'knowledge_document_id';
    }
}
