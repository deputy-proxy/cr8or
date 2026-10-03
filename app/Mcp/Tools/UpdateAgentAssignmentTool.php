<?php

namespace App\Mcp\Tools;

use App\Operations\UpdateAgentAssignment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mcp_agent_assignment_update')]
#[Description('Update an Enterprise-scoped Agent Assignment definition without bypassing lifecycle rules.')]
class UpdateAgentAssignmentTool extends AgentAssignmentResourceTool
{
    protected function operationClass(): string
    {
        return UpdateAgentAssignment::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_assignment_id' => $schema->integer()->min(1)->required(),
            'agent_descriptor_id' => $schema->integer()->min(1),
            'objective' => $schema->string()->max(10000),
            'requirements' => $schema->object(),
            'context' => $schema->object(),
            'correlation_id' => $schema->string()->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_descriptor_id' => ['nullable', 'integer', 'min:1', 'exists:agent_descriptors,id'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
        ]));
    }
}