<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delegate-agent')]
#[Description('Delegate governed work from one Agent assignment to another Agent in the same Enterprise.')]
final class DelegateAgentTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'source_agent_assignment_id' => $schema->integer()->min(1)->required(),
            'target_agent_slug' => $schema->string()->min(1)->max(100)->required(),
            'capability' => $schema->string()->min(1)->max(100)->required(),
            'prompt' => $schema->string()->min(1)->max(20000)->required(),
            'target_context' => $schema->object()->description('JSON object describing the delegated target context.'),
            'source_approval_request_id' => $schema->integer()->min(1),
            'target_approval_request_id' => $schema->integer()->min(1),
            'parent_agent_execution_id' => $schema->integer()->min(1),
            'correlation_id' => $schema->string()->min(1)->max(255),
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.agent.delegate', function () use ($request, $registry) {
            $validated = $request->validate([
                'source_agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'target_agent_slug' => ['required', 'string', 'min:1', 'max:100'],
                'capability' => ['required', 'string', 'min:1', 'max:100'],
                'prompt' => ['required', 'string', 'min:1', 'max:20000'],
                'target_context' => ['nullable', 'array'],
                'source_approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
                'target_approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
                'parent_agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'correlation_id' => ['nullable', 'string', 'min:1', 'max:255'],
                'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $source = AgentAssignment::query()
                ->with(['agentDescriptor', 'organization', 'enterprise'])
                ->findOrFail($validated['source_agent_assignment_id']);

            $sourceApproval = isset($validated['source_approval_request_id'])
                ? ApprovalRequest::query()->findOrFail($validated['source_approval_request_id'])
                : null;
            $targetApproval = isset($validated['target_approval_request_id'])
                ? ApprovalRequest::query()->findOrFail($validated['target_approval_request_id'])
                : null;
            $parentExecution = isset($validated['parent_agent_execution_id'])
                ? AgentExecution::query()->findOrFail($validated['parent_agent_execution_id'])
                : null;

            $response = $this->executeCapability($registry, $actor, [
                ...$validated,
                'source_assignment' => $source,
                'source_approval' => $sourceApproval,
                'target_approval' => $targetApproval,
                'parent_execution' => $parentExecution,
            ]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'delegation_id' => $response->delegation->getKey(),
                    'status' => $response->delegation->status,
                    'source_agent' => $response->sourceDescriptor->slug,
                    'target_agent' => $response->targetDescriptor->slug,
                    'capability' => $response->capability,
                    'correlation_id' => $response->correlationId,
                    'execution_id' => $response->execution?->getKey(),
                ],
            ]);
        });
    }
}