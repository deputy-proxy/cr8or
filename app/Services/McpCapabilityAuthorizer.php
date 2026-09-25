<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class McpCapabilityAuthorizer
{
    /**
     * Authorize a capability that may be invoked by a human or an Agent-backed MCP call.
     *
     * @param  array<string, mixed>  $targetContext
     */
    public function authorizeCapability(
        User $actor,
        string $capability,
        Enterprise $enterprise,
        ?int $assignmentId,
        ?int $executionId,
        ?int $approvalId,
        array $targetContext,
    ): void {
        if (($assignmentId === null) !== ($executionId === null)) {
            throw new AuthorizationException('Agent-backed MCP capabilities require both an assignment and execution context.');
        }

        if ($assignmentId === null) {
            Gate::forUser($actor)->authorize('view', $enterprise);

            return;
        }

        $assignment = AgentAssignment::query()
            ->with(['agentDescriptor', 'organization', 'enterprise'])
            ->findOrFail($assignmentId);

        Gate::forUser($actor)->authorize('view', $assignment);

        $execution = AgentExecution::query()->findOrFail($executionId);

        if (
            $execution->actor_id !== $actor->getKey()
            || $execution->agent_assignment_id !== $assignment->getKey()
            || $execution->organization_id !== $assignment->organization_id
            || $execution->enterprise_id !== $assignment->enterprise_id
            || $execution->enterprise_id !== $enterprise->getKey()
        ) {
            throw new AuthorizationException('The Agent execution context does not match the requested MCP target.');
        }

        $approval = $approvalId === null
            ? null
            : ApprovalRequest::query()->findOrFail($approvalId);

        if (! app(AgentCapabilityAuthorizer::class)->allows(
            $assignment,
            $capability,
            Organization::query()->find($assignment->organization_id),
            $enterprise,
            $actor,
            $approval,
            $execution,
            $targetContext,
        )) {
            throw new AuthorizationException("The Agent is not authorized for capability [{$capability}] in this target context.");
        }
    }

    /**
     * @param  array<string, mixed>  $targetContext
     * @param  array{0: string, 1: mixed}  $humanAbility
     */
    public function authorizeMutation(
        User $actor,
        string $capability,
        Enterprise $enterprise,
        ?int $assignmentId,
        ?int $executionId,
        ?int $approvalId,
        array $targetContext,
        array $humanAbility,
    ): void {
        if (($assignmentId === null) !== ($executionId === null)) {
            throw new AuthorizationException('Agent-backed MCP mutations require both an assignment and execution context.');
        }

        if ($assignmentId === null) {
            Gate::forUser($actor)->authorize($humanAbility[0], $humanAbility[1]);

            return;
        }

        $this->authorizeCapability(
            $actor,
            $capability,
            $enterprise,
            $assignmentId,
            $executionId,
            $approvalId,
            $targetContext,
        );
    }

    public function authorizeApprovalRequest(
        User $actor,
        AgentAssignment $assignment,
        ?AgentExecution $execution,
        string $capability,
    ): void {
        Gate::forUser($actor)->authorize('view', $assignment);

        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException('The Agent assignment is disabled.');
        }

        $permission = $assignment->permissions()->where('capability', $capability)->first();

        if ($permission === null || ! $permission->requires_approval) {
            throw new AuthorizationException('The requested capability is not configured to require approval.');
        }

        if ($execution === null) {
            return;
        }

        if (
            $execution->actor_id !== $actor->getKey()
            || $execution->agent_assignment_id !== $assignment->getKey()
            || $execution->organization_id !== $assignment->organization_id
            || $execution->enterprise_id !== $assignment->enterprise_id
        ) {
            throw new AuthorizationException('The Agent execution context does not match the approval request.');
        }
    }
}
