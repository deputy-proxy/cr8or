<?php

namespace App\Mcp\Tools;

use App\Operations\ListAgentDelegations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-agent-delegations')]
#[Description('List bounded Enterprise-scoped Agent Delegations with execution relationship identifiers.')]
class ListAgentDelegationsTool extends AgentAssignmentResourceTool
{
    protected function operationClass(): string
    {
        return ListAgentDelegations::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'source_agent_assignment_id' => $schema->integer()->min(1), 'target_agent_assignment_id' => $schema->integer()->min(1), 'status' => $schema->string()->enum(['pending', 'running', 'succeeded', 'failed']), 'limit' => $schema->integer()->min(1)->max(50)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'source_agent_assignment_id' => ['nullable', 'integer', 'min:1'], 'target_agent_assignment_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'string', 'in:pending,running,succeeded,failed'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]));
    }
}