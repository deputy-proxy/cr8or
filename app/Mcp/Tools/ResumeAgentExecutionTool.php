<?php

namespace App\Mcp\Tools;

use App\Operations\ResumeAgentExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('resume-execution')] #[Description('Resume a paused or waiting Agent Execution through the canonical runtime.') ] class ResumeAgentExecutionTool extends AgentExecutionResourceTool
{
    protected function operationClass(): string
    {
        return ResumeAgentExecution::class;
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
