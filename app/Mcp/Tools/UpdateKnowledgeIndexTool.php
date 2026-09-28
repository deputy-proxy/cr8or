<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeIndex;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-index')]
#[Description('Rebuild a derived Knowledge index record from its authoritative Knowledge Item.')]
class UpdateKnowledgeIndexTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeIndex::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_index_id' => $schema->integer()->min(1)->required(),
            'correlation_id' => $schema->string()->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_index_id' => ['required', 'integer', 'min:1', 'exists:knowledge_index_records,id'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
        ]));
    }
}