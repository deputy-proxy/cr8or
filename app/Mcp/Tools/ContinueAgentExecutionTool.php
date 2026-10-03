<?php

namespace App\Mcp\Tools;

use App\Operations\ContinueAgentExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mcp_agent_continue')]
#[Description('Submit the current interactive reasoning result and continue a durable Agent Execution.')]
final class ContinueAgentExecutionTool extends AgentExecutionResourceTool
{
    protected function operationClass(): string
    {
        return ContinueAgentExecution::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_execution_id' => $schema->integer()->min(1)->required(),
            'expected_step' => $schema->integer()->min(1)->required(),
            'idempotency_key' => $schema->string()->min(1)->max(128)->required(),
            'reasoning' => $schema->string()->max(20000),
            'capability_requests' => $schema->array(),
            'delegation_requests' => $schema->array(),
            'termination' => $schema->string()->enum(['continue', 'waiting_for_input', 'waiting_for_approval', 'delegated', 'paused', 'completed'])->required(),
            'termination_reason' => $schema->string()->max(2000),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'agent_execution_id' => ['required', 'integer', 'min:1', 'exists:agent_executions,id'],
            'expected_step' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128'],
            'reasoning' => ['nullable', 'string', 'max:20000'],
            'capability_requests' => ['nullable', 'array'],
            'capability_requests.*.capability' => ['required', 'string', 'min:1'],
            'capability_requests.*.expert_slug' => ['required', 'string', 'min:1', 'max:100'],
            'capability_requests.*.step' => ['nullable', 'integer', 'min:1'],
            'capability_requests.*.target_context' => ['nullable', 'array'],
            'capability_requests.*.input_payload' => ['nullable', 'array'],
            'capability_requests.*.approval_request_id' => ['nullable', 'integer', 'min:1'],
            'capability_requests.*.idempotency_key' => ['nullable', 'string', 'min:1', 'max:128'],
            'delegation_requests' => ['nullable', 'array'],
            'termination' => ['required', 'string', 'in:continue,waiting_for_input,waiting_for_approval,delegated,paused,completed'],
            'termination_reason' => ['nullable', 'string', 'max:2000'],
        ]));
    }
}