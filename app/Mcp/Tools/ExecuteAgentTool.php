<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Enums\AgentExecutionMode;
use App\Models\Enterprise;
use App\Models\User;
use App\Operations\ExecuteAgent;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mcp_agent_execute')]
#[Description('Start a durable Agent Execution through the governed agent.execute capability.')]
class ExecuteAgentTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'agent_assignment_id' => $schema->integer()->min(1)->required(), 'prompt' => $schema->string()->min(1)->max(20000)->required(), 'mode' => $schema->string()->enum(array_column(AgentExecutionMode::cases(), 'value'))->required(), 'capability_requests' => $schema->array(), 'target_context' => $schema->object(), 'expert_slugs' => $schema->array(), 'options' => $schema->object(), 'correlation_id' => $schema->string()->max(255), 'idempotency_key' => $schema->string()->min(1)->max(128)];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.agent.execute', function () use ($request, $authorization, $registry) {
            $validated = $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'], 'prompt' => ['required', 'string', 'min:1', 'max:20000'], 'mode' => ['required', 'string', 'in:interactive,autonomous'], 'capability_requests' => ['nullable', 'array'], 'capability_requests.*.capability' => ['required', 'string', 'min:1'], 'capability_requests.*.expert_slug' => ['required', 'string', 'min:1', 'max:100'], 'capability_requests.*.step' => ['nullable', 'integer', 'min:1'], 'capability_requests.*.target_context' => ['nullable', 'array'], 'capability_requests.*.input_payload' => ['nullable', 'array'], 'capability_requests.*.approval_request_id' => ['nullable', 'integer', 'min:1'], 'capability_requests.*.idempotency_key' => ['nullable', 'string', 'min:1', 'max:128'], 'target_context' => ['nullable', 'array'], 'expert_slugs' => ['nullable', 'array'], 'options' => ['nullable', 'array'], 'correlation_id' => ['nullable', 'string', 'max:255'], 'idempotency_key' => ['nullable', 'string', 'min:1', 'max:128']]);
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }
            $enterprise = Enterprise::query()->whereKey($validated['enterprise_id'])->firstOrFail();
            $definition = $registry->forTool(static::class);
            $authorization->authorizeCapability($actor, $definition->key, $enterprise, null, null, null, ['agent_assignment_id' => $validated['agent_assignment_id']]);

            return Response::structured(['success' => true, 'result' => app(ExecuteAgent::class)->execute($actor, $validated)]);
        });
    }
}