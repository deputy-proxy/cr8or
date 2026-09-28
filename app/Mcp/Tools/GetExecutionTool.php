<?php

namespace App\Mcp\Tools;

use App\Operations\GetAgentExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-execution')]
#[Description('Get an authorized Agent Execution by id from CR8OR.')]
class GetExecutionTool extends AgentExecutionResourceTool
{
    protected function operationClass(): string
    {
        return GetAgentExecution::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'agent_execution_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'agent_execution_id' => ['required', 'integer', 'min:1', 'exists:agent_executions,id']]));
    }
}
