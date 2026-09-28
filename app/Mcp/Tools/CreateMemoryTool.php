<?php

namespace App\Mcp\Tools;

use App\Operations\CreateMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-memory')]
#[Description('Create durable, explicitly sourced episodic or semantic Agent Memory in an authorized Enterprise.')]
class CreateMemoryTool extends MemoryResourceTool
{
    protected function operationClass(): string
    {
        return CreateMemory::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'type' => $schema->string()->enum(['episodic', 'semantic'])->required(),
            'source_execution_id' => $schema->integer()->min(1)->required(),
            'agent_descriptor_id' => $schema->integer()->min(1),
            'objective' => $schema->string()->max(2000),
            'action' => $schema->string()->max(2000),
            'result' => $schema->string()->max(2000),
            'outcome' => $schema->string()->max(2000),
            'topic' => $schema->string()->max(200),
            'statement' => $schema->string()->max(2000),
            'confidence' => $schema->number()->min(0)->max(1),
            'occurred_at' => $schema->string()->max(100),
            'source_step' => $schema->integer()->min(1),
            'reason' => $schema->string()->max(500),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'type' => ['required', 'string', 'in:episodic,semantic'],
            'source_execution_id' => ['required', 'integer', 'min:1', 'exists:agent_executions,id'],
            'agent_descriptor_id' => ['required_if:type,semantic', 'nullable', 'integer', 'min:1', 'exists:agent_descriptors,id'],
            'objective' => ['required_if:type,episodic', 'nullable', 'string', 'max:2000'],
            'action' => ['required_if:type,episodic', 'nullable', 'string', 'max:2000'],
            'result' => ['required_if:type,episodic', 'nullable', 'string', 'max:2000'],
            'outcome' => ['required_if:type,episodic', 'nullable', 'string', 'max:2000'],
            'topic' => ['nullable', 'string', 'max:200'],
            'statement' => ['required_if:type,semantic', 'nullable', 'string', 'max:2000'],
            'confidence' => ['required_if:type,semantic', 'nullable', 'numeric', 'min:0', 'max:1'],
            'occurred_at' => ['nullable', 'date'],
            'source_step' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]));
    }
}