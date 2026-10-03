<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityInvocationRequest;
use App\Mcp\McpFailureResponder;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\CapabilityInvocationService;
use Closure;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class AuthorizedTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->memberships()->exists();
    }

    protected function executeWithErrors(Request $request, string $operation, Closure $callback): Response|ResponseFactory
    {
        return app(McpFailureResponder::class)->execute($request, $operation, $callback);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $targetContext
     * @param  array{0: string, 1: mixed}|null  $humanAbility
     */
    protected function invokeCapability(
        CapabilityRegistry $registry,
        User $actor,
        ?Enterprise $enterprise,
        array $input,
        array $targetContext = [],
        ?array $humanAbility = null,
        ?string $expertSlug = null,
    ): mixed {
        $assignment = ($input['assignment'] ?? null) instanceof AgentAssignment
            ? $input['assignment']
            : (isset($input['agent_assignment_id'])
                ? AgentAssignment::query()->with(['agentDescriptor', 'organization', 'enterprise'])->findOrFail((int) $input['agent_assignment_id'])
                : null);
        $execution = ($input['execution'] ?? null) instanceof AgentExecution
            ? $input['execution']
            : (isset($input['agent_execution_id'])
                ? AgentExecution::query()->findOrFail((int) $input['agent_execution_id'])
                : null);
        $approval = ($input['approval'] ?? null) instanceof ApprovalRequest
            ? $input['approval']
            : (isset($input['approval_request_id'])
                ? ApprovalRequest::query()->findOrFail((int) $input['approval_request_id'])
                : null);

        return app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
            capability: $registry->forTool(static::class)->key,
            actor: $actor,
            enterprise: $enterprise,
            targetContext: $targetContext,
            inputPayload: $input,
            assignment: $assignment,
            execution: $execution,
            approval: $approval,
            correlationId: isset($input['correlation_id']) && is_string($input['correlation_id']) ? $input['correlation_id'] : null,
            idempotencyKey: isset($input['idempotency_key']) && is_string($input['idempotency_key']) ? $input['idempotency_key'] : null,
            expertSlug: $expertSlug ?? (isset($input['expert_slug']) && is_string($input['expert_slug']) ? $input['expert_slug'] : null),
            humanAbility: $humanAbility,
        ))['raw_result'] ?? null;
    }
}