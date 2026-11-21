<?php

namespace App\Mcp\Tools;

use App\Operations\ListKnowledgeItems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-knowledge-items')]
#[Description('List authorized Knowledge Items in one Enterprise with bounded pagination and optional title, summary or type filters.')]
class ListKnowledgeItemsTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return ListKnowledgeItems::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'search' => $schema->string()->max(255),
            'type' => $schema->string()->max(100),
            'per_page' => $schema->integer()->min(1)->max(50),
            'page' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]));
    }
}
