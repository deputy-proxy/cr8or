<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-item')]
#[Description('Update mutable Knowledge Item metadata and/or create a new authoritative content version. Existing version history is preserved and derived indexes are invalidated when content or indexed metadata changes.')]
class UpdateKnowledgeItemTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeItem::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_item_id' => $schema->integer()->min(1)->required(),
            'title' => $schema->string()->min(1)->max(255),
            'type' => $schema->string()->min(1)->max(100),
            'summary' => $schema->string()->nullable()->max(5000),
            'content' => $schema->string()->min(1)->max(50000),
            'context_snapshot' => $schema->object()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1'],
            'knowledge_item_id' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'type' => ['sometimes', 'string', 'min:1', 'max:100'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'content' => ['sometimes', 'string', 'min:1', 'max:50000'],
            'context_snapshot' => ['sometimes', 'nullable', 'array'],
        ]));
    }
}
