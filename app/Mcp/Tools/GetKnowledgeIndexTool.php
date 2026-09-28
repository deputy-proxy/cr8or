<?php

namespace App\Mcp\Tools;

use App\Operations\GetKnowledgeIndex;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-knowledge-index')]
#[Description('Inspect one authorized derived Knowledge index record.')]
class GetKnowledgeIndexTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return GetKnowledgeIndex::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_index_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_index_id' => ['required', 'integer', 'min:1', 'exists:knowledge_index_records,id'],
        ]));
    }
}