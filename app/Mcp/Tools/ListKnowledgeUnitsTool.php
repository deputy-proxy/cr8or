<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeUnits;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-units')]
#[Description('List bounded, enterprise-scoped indexed Knowledge Units.')]
class ListKnowledgeUnitsTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeUnits::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_index_id' => $schema->integer()->min(1),
            'knowledge_item_id' => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
            'page' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_index_id' => ['nullable', 'integer', 'min:1', 'exists:knowledge_index_records,id'],
            'knowledge_item_id' => ['nullable', 'integer', 'min:1', 'exists:knowledge_items,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]));
    }
}