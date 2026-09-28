<?php

namespace App\Mcp\Tools;

use App\Operations\CreateKnowledgeUnit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-unit')]
#[Description('Create or rebuild one derived Knowledge Unit from authoritative Knowledge.')]
class CreateKnowledgeUnitTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return CreateKnowledgeUnit::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_item_id' => $schema->integer()->min(1)->required(),
            'unit_key' => $schema->string()->min(1)->max(255)->required(),
            'correlation_id' => $schema->string()->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_item_id' => ['required', 'integer', 'min:1', 'exists:knowledge_items,id'],
            'unit_key' => ['required', 'string', 'min:1', 'max:255'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
        ]));
    }
}