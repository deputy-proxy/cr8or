<?php

namespace App\Mcp\Tools;

use App\Operations\ListAgentAssignments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-agent-assignments')]
#[Description('List Enterprise-scoped Agent Assignments with optional Agent and lifecycle filters.')]
class ListAgentAssignmentsTool extends AgentAssignmentResourceTool
{
    protected function operationClass(): string
    {
        return ListAgentAssignments::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_descriptor_id' => $schema->integer()->min(1),
            'status' => $schema->string()->enum(['draft', 'ready', 'running', 'paused', 'completed', 'failed', 'cancelled']),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'agent_descriptor_id' => ['nullable', 'integer', 'min:1', 'exists:agent_descriptors,id'],
            'status' => ['nullable', 'string', 'in:draft,ready,running,paused,completed,failed,cancelled'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]));
    }
}
