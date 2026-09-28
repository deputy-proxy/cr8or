<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeIndexes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-indexes')]
#[Description('List bounded, enterprise-scoped Knowledge index records.')]
class ListKnowledgeIndexesTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeIndexes::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_item_id' => $schema->integer()->min(1),
            'status' => $schema->string()->max(50),
            'search' => $schema->string()->max(255),
            'per_page' => $schema->integer()->min(1)->max(50),
            'page' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_item_id' => ['nullable', 'integer', 'min:1', 'exists:knowledge_items,id'],
            'status' => ['nullable', 'string', 'max:50', 'in:pending,indexed,failed,stale,removed'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]));
    }
}