<?php

namespace App\Mcp\Tools;

use App\Enums\AgentExecutionMode;
use App\Operations\CreateAgentExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-agent-execution')] #[Description('Queue a durable Agent Execution from an authorized Assignment.') ] class CreateAgentExecutionTool extends AgentExecutionResourceTool
{
    protected function operationClass(): string
    {
        return CreateAgentExecution::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'agent_assignment_id' => $schema->integer()->min(1)->required(), 'prompt' => $schema->string()->min(1)->max(20000)->required(), 'mode' => $schema->string()->enum(array_column(AgentExecutionMode::cases(), 'value'))->required(), 'capability_requests' => $schema->array(), 'target_context' => $schema->object(), 'expert_slugs' => $schema->array(), 'options' => $schema->object(), 'correlation_id' => $schema->string()->max(255), 'idempotency_key' => $schema->string()->min(1)->max(128)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'], 'prompt' => ['required', 'string', 'min:1', 'max:20000'], 'mode' => ['required', 'string', 'in:interactive,autonomous'], 'capability_requests' => ['nullable', 'array'], 'capability_requests.*.capability' => ['required', 'string', 'min:1'], 'capability_requests.*.step' => ['nullable', 'integer', 'min:1'], 'capability_requests.*.target_context' => ['nullable', 'array'], 'capability_requests.*.input_payload' => ['nullable', 'array'], 'capability_requests.*.approval_request_id' => ['nullable', 'integer', 'min:1'], 'capability_requests.*.idempotency_key' => ['nullable', 'string', 'min:1', 'max:128'], 'target_context' => ['nullable', 'array'], 'expert_slugs' => ['nullable', 'array'], 'options' => ['nullable', 'array'], 'correlation_id' => ['nullable', 'string', 'max:255'], 'idempotency_key' => ['nullable', 'string', 'min:1', 'max:128']]));
    }
}