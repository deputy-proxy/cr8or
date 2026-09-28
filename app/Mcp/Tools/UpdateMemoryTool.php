<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-memory')]
#[Description('Update a semantic Memory record through its governed provenance/versioning rules. Episodic Memory is immutable.')]
class UpdateMemoryTool extends MemoryResourceTool
{
    protected function operationClass(): string
    {
        return UpdateMemory::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'type' => $schema->string()->enum(['semantic'])->required(),
            'memory_id' => $schema->integer()->min(1)->required(),
            'source_execution_id' => $schema->integer()->min(1)->required(),
            'statement' => $schema->string()->max(2000)->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'source_step' => $schema->integer()->min(1),
            'reason' => $schema->string()->max(500),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'type' => ['required', 'string', 'in:semantic'],
            'memory_id' => ['required', 'integer', 'min:1'],
            'source_execution_id' => ['required', 'integer', 'min:1', 'exists:agent_executions,id'],
            'statement' => ['required', 'string', 'max:2000'],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'source_step' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]));
    }
}