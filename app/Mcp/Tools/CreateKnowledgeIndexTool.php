<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeIndex;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-index')]
#[Description('Create or rebuild the authorized derived Knowledge index for an authoritative Knowledge Item.')]
class CreateKnowledgeIndexTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeIndex::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->description('Enterprise that owns the Knowledge Item.')->required(),
            'knowledge_item_id' => $schema->integer()->min(1)->description('Authoritative Knowledge Item to index.')->required(),
            'correlation_id' => $schema->string()->max(255)->description('Optional correlation identifier.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_item_id' => ['required', 'integer', 'min:1', 'exists:knowledge_items,id'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
        ]));
    }
}