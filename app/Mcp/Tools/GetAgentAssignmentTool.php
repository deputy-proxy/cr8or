<?php

namespace App\Mcp\Tools;

use App\Operations\GetAgentAssignment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-agent-assignment')]
#[Description('Inspect one Enterprise-scoped Agent Assignment.')]
class GetAgentAssignmentTool extends AgentAssignmentResourceTool
{
    protected function operationClass(): string
    {
        return GetAgentAssignment::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_assignment_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
        ]));
    }
}
