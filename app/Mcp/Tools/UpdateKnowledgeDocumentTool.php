<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeDocument;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-document')]
#[Description('Update Enterprise-scoped Knowledge Document through the governed Knowledge capability.')]
final class UpdateKnowledgeDocumentTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeDocument::class;
    }

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
