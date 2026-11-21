<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeDocument;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-document')]
#[Description('Get Enterprise-scoped Knowledge Document through the governed Knowledge capability.')]
final class GetKnowledgeDocumentTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeDocument::class;
    }

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
