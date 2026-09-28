<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-item')]
#[Description('Create authoritative Enterprise-scoped Knowledge content that can be indexed and retrieved through the governed Knowledge surface.')]
class CreateKnowledgeItemTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeItem::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'title' => $schema->string()->min(1)->max(255)->required(), 'type' => $schema->string()->max(100), 'summary' => $schema->string()->max(5000), 'content' => $schema->string()->min(1)->max(50000)->required(), 'context_snapshot' => $schema->object()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'title' => ['required', 'string', 'min:1', 'max:255'], 'type' => ['nullable', 'string', 'max:100'], 'summary' => ['nullable', 'string', 'max:5000'], 'content' => ['required', 'string', 'min:1', 'max:50000'], 'context_snapshot' => ['nullable', 'array']]));
    }
}
