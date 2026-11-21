<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeDocument;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-document')]
#[Description('Create Enterprise-scoped Knowledge Document through the governed Knowledge capability.')]
final class CreateKnowledgeDocumentTool extends KnowledgeCatalogTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeDocument::class;
    }

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
