<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateKnowledgeUnit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-knowledge-unit')]
#[Description('Rebuild a derived Knowledge Unit from authoritative Knowledge.')]
class UpdateKnowledgeUnitTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return UpdateKnowledgeUnit::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_unit_id' => $schema->integer()->min(1)->required(),
            'correlation_id' => $schema->string()->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_unit_id' => ['required', 'integer', 'min:1', 'exists:knowledge_index_units,id'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
        ]));
    }
}