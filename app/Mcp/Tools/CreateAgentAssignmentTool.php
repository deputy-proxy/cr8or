<?php

namespace App\Mcp\Tools;

use App\Operations\CreateAgentAssignment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-agent-assignment')]
#[Description('Create an Enterprise-scoped Agent Assignment with durable objective, requirements, context and idempotency state.')]
class CreateAgentAssignmentTool extends AgentAssignmentResourceTool
{
    protected function operationClass(): string
    {
        return CreateAgentAssignment::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_descriptor_id' => $schema->integer()->min(1)->required(),
            'objective' => $schema->string()->max(10000),
            'requirements' => $schema->object(),
            'context' => $schema->object(),
            'status' => $schema->string()->enum(['draft', 'ready'])->description('Initial lifecycle state.'),
            'correlation_id' => $schema->string()->max(255),
            'idempotency_key' => $schema->string()->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'agent_descriptor_id' => ['required', 'integer', 'min:1', 'exists:agent_descriptors,id'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:draft,ready'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]));
    }
}
