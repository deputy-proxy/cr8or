<?php

namespace App\Mcp\Tools;

use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\ApprovalRequestService;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('request-approval')]
#[Description('Create a governed approval request for an Agent capability and target context.')]
class RequestApprovalTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_assignment_id' => $schema->integer()->min(1)->description('Agent assignment requesting approval.')->required(),
            'agent_execution_id' => $schema->integer()->min(1)->description('Optional Agent execution associated with the request.'),
            'capability' => $schema->string()->min(1)->max(255)->description('Capability that requires approval.')->required(),
            'target_context' => $schema->object()->description('Exact target context that must match when the approval is consumed.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, ApprovalRequestService $approvals): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.approval.request', function (string $correlationId) use ($request, $authorization, $approvals) {
            $validated = $request->validate([
                'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'capability' => ['required', 'string', 'min:1', 'max:255'],
                'target_context' => ['nullable', 'array'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var AgentAssignment $assignment */
            $assignment = AgentAssignment::query()->with('agentDescriptor')->findOrFail($validated['agent_assignment_id']);
            /** @var AgentExecution|null $execution */
            $execution = isset($validated['agent_execution_id'])
                ? AgentExecution::query()->findOrFail($validated['agent_execution_id'])
                : null;

            $targetContext = $validated['target_context'] ?? [];

            $authorization->authorizeApprovalRequest(
                $actor,
                $assignment,
                $execution,
                $validated['capability'],
            );

            $approval = $approvals->request(
                $actor,
                $validated['capability'],
                $assignment,
                $execution,
                $targetContext,
                $correlationId,
            );

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $approval->getKey(),
                    'status' => $approval->status,
                    'capability' => $approval->capability,
                    'organization_id' => $approval->organization_id,
                    'enterprise_id' => $approval->enterprise_id,
                    'agent_assignment_id' => $approval->agent_assignment_id,
                    'agent_execution_id' => $approval->agent_execution_id,
                    'correlation_id' => $approval->correlation_id,
                    'target_context' => $approval->target_context,
                    'requested_at' => $approval->requested_at->toIso8601String(),
                    'expires_at' => $approval->expires_at->toIso8601String(),
                ],
            ]);
        });
    }
}
