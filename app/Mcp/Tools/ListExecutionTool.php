<?php

namespace App\Mcp\Tools;

use App\Operations\ListAgentExecutions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-executions')]
#[Description('List bounded Agent Executions for an Enterprise.')]
class ListExecutionTool extends AgentExecutionResourceTool
{
    protected function operationClass(): string
    {
        return ListAgentExecutions::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'agent_assignment_id' => $schema->integer()->min(1), 'status' => $schema->string()->max(64), 'limit' => $schema->integer()->min(1)->max(50)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'status' => ['nullable', 'string', 'max:64'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]));
    }
}
