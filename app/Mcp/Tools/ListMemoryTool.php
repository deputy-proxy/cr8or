<?php

namespace App\Mcp\Tools;

use App\Operations\ListMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-memory')]
#[Description('List bounded Enterprise-scoped episodic and semantic Memory, with optional search filters.')]
class ListMemoryTool extends MemoryResourceTool
{
    protected function operationClass(): string
    {
        return ListMemory::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'type' => $schema->string()->enum(['episodic', 'semantic']),
            'agent_descriptor_id' => $schema->integer()->min(1),
            'search' => $schema->string()->max(255),
            'status' => $schema->string()->max(50),
            'page' => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'type' => ['nullable', 'string', 'in:episodic,semantic'],
            'agent_descriptor_id' => ['nullable', 'integer', 'min:1', 'exists:agent_descriptors,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,disputed,superseded,archived'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]));
    }
}