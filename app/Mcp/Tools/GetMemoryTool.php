<?php

namespace App\Mcp\Tools;

use App\Operations\GetMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-memory')]
#[Description('Inspect one enterprise-scoped episodic or semantic Memory record.')]
class GetMemoryTool extends MemoryResourceTool
{
    protected function operationClass(): string
    {
        return GetMemory::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'type' => $schema->string()->enum(['episodic', 'semantic'])->required(),
            'memory_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'type' => ['required', 'string', 'in:episodic,semantic'],
            'memory_id' => ['required', 'integer', 'min:1'],
        ]));
    }
}