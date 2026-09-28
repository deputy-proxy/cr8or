<?php

namespace App\Mcp\Tools;

use App\Operations\ArchiveKnowledgeUnit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('archive-knowledge-unit')]
#[Description('Archive a derived Knowledge Unit without deleting authoritative Knowledge or historical index data.')]
class ArchiveKnowledgeUnitTool extends KnowledgeResourceTool
{
    protected function operationClass(): string
    {
        return ArchiveKnowledgeUnit::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_unit_id' => $schema->integer()->min(1)->required(),
            'reason' => $schema->string()->max(1000),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'knowledge_unit_id' => ['required', 'integer', 'min:1', 'exists:knowledge_index_units,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]));
    }
}