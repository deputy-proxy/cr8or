<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-item')]
#[Description('Get one authorized Enterprise-scoped Knowledge Item, including its latest version and provenance relationships.')]
class GetKnowledgeItemTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeItem::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_item_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1'],
            'knowledge_item_id' => ['required', 'integer', 'min:1'],
        ]));
    }
}
