<?php

namespace App\Mcp\Tools;

use App\Operations\TransitionAgentAssignment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('transition-agent-assignment')]
#[Description('Transition an Enterprise-scoped Agent Assignment through its explicit lifecycle state machine.')]
class TransitionAgentAssignmentTool extends AgentAssignmentResourceTool
{
    protected function operationClass(): string
    {
        return TransitionAgentAssignment::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_assignment_id' => $schema->integer()->min(1)->required(),
            'status' => $schema->string()->enum(['ready', 'running', 'paused', 'completed', 'failed', 'cancelled'])->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'status' => ['required', 'string', 'in:ready,running,paused,completed,failed,cancelled'],
        ]));
    }
}
