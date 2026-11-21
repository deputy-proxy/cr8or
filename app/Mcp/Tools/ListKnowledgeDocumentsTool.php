<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeDocuments;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-documents')]
#[Description('List Enterprise-scoped Knowledge Documents through the governed Knowledge capability.')]
final class ListKnowledgeDocumentsTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeDocuments::class;
    }

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
