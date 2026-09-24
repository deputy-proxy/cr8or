<?php

namespace App\Services;

use App\Data\AgentDelegationRequest;
use App\Data\AgentDelegationResponse;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class AgentDelegationService
{
    public const DELEGATION_CAPABILITY = 'agent.delegate';

    public function __construct(
        private readonly AgentCapabilityAuthorizer $capabilityAuthorizer,
        private readonly ExecutionCorrelationService $correlation,
        private readonly AgentExecutionService $executionService,
        private readonly ApprovalRequestService $approvalRequests,
    ) {}

    public function delegate(AgentDelegationRequest $request): AgentDelegationResponse
    {
        $source = $request->sourceAssignment->loadMissing(['agentDescriptor', 'organization', 'enterprise']);
        Gate::forUser($request->actor)->authorize('view', $source);
        $this->assertEnabled($source, 'source');

        $target = $this->resolveTarget($source, $request->targetAgentSlug);
        $this->assertEnabled($target, 'target');

        if ($target->organization_id !== $source->organization_id) {
            throw new AuthorizationException('Delegation cannot cross organization boundaries.');
        }

        if ($target->enterprise_id !== $source->enterprise_id) {
            throw new AuthorizationException('Delegation cannot cross Enterprise boundaries.');
        }

        $correlationId = $this->correlation->resolve($request->correlationId);
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        $parentExecution = $request->parentExecution;

        if ($parentExecution !== null && (
            $parentExecution->organization_id !== $source->organization_id
            || $parentExecution->enterprise_id !== $source->enterprise_id
            || $parentExecution->agent_assignment_id !== $source->getKey()
        )) {
            throw new AuthorizationException('The parent Agent execution is outside the source Agent scope.');
        }

        $delegationContext = array_merge($request->targetContext, [
            'target_agent_slug' => $target->agentDescriptor->slug,
            'target_capability' => $request->capability,
        ]);

        $delegation = $this->findOrCreateDelegation(
            $request,
            $source,
            $target,
            $parentExecution,
            $correlationId,
            $idempotencyKey,
        );

        if ($request->sourceApproval !== null) {
            $this->approvalRequests->bindToDelegation($request->sourceApproval, $delegation);
        }

        if ($request->targetApproval !== null) {
            $this->approvalRequests->bindToDelegation($request->targetApproval, $delegation);
        }

        if (! $this->capabilityAuthorizer->allows(
            $source,
            self::DELEGATION_CAPABILITY,
            $source->organization,
            $source->enterprise,
            $request->actor,
            $request->sourceApproval,
            $parentExecution,
            $delegationContext,
            $delegation,
        )) {
            throw new AuthorizationException('The source Agent is not authorized to delegate this work.');
        }

        if ($request->sourceApproval !== null) {
            $this->approvalRequests->consumeForDelegation($request->sourceApproval, $delegation);
        }

        if (! $this->capabilityAuthorizer->allows(
            $target,
            $request->capability,
            $target->organization,
            $target->enterprise,
            $request->actor,
            $request->targetApproval,
            null,
            $request->targetContext,
            $delegation,
        )) {
            throw new AuthorizationException(sprintf(
                'The target Agent is not authorized for capability [%s].',
                $request->capability,
            ));
        }

        if ($delegation->status === AgentDelegation::STATUS_SUCCEEDED
            || $delegation->status === AgentDelegation::STATUS_RUNNING
        ) {
            return $this->response($request, $source, $target, $delegation);
        }

        if ($delegation->status === AgentDelegation::STATUS_FAILED) {
            $delegation->retry()->save();
        }

        try {
            $delegation->start()->save();

            $result = $this->executionService->execute(
                $request->actor,
                $target,
                $request->prompt,
                $request->targetContext,
                modelOptions: ['correlation_id' => $correlationId, 'delegation_id' => $delegation->getKey()],
            );

            $delegation->target_agent_execution_id = $result->execution->getKey();
            $delegation->succeed()->save();

            return $this->response($request, $source, $target, $delegation, $result->execution);
        } catch (Throwable $exception) {
            if ($delegation->status === AgentDelegation::STATUS_RUNNING) {
                $execution = AgentExecution::query()->where('organization_id', $source->organization_id)->where('enterprise_id', $source->enterprise_id)->where('agent_assignment_id', $target->getKey())->where('correlation_id', $correlationId)->latest('id')->first();
                if ($execution !== null) {
                    $delegation->target_agent_execution_id = $execution->getKey();
                }
                $delegation->fail($exception->getMessage())->save();
            }

            throw $exception;
        }
    }

    private function findOrCreateDelegation(
        AgentDelegationRequest $request,
        AgentAssignment $source,
        AgentAssignment $target,
        ?AgentExecution $parentExecution,
        string $correlationId,
        string $idempotencyKey,
    ): AgentDelegation {
        $delegation = AgentDelegation::query()
            ->where('organization_id', $source->organization_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($delegation !== null) {
            $this->assertSameRequest($delegation, $source, $target, $request);

            return $delegation;
        }

        try {
            return AgentDelegation::query()->create([
                'organization_id' => $source->organization_id,
                'enterprise_id' => $source->enterprise_id,
                'source_agent_assignment_id' => $source->getKey(),
                'target_agent_assignment_id' => $target->getKey(),
                'parent_agent_execution_id' => $parentExecution?->getKey(),
                'actor_id' => $request->actor->getKey(),
                'organization_name' => $source->organization->name,
                'enterprise_name' => $source->enterprise->name,
                'source_agent_slug' => $source->agentDescriptor->slug,
                'source_agent_runtime_class' => $source->agentDescriptor->runtime_class,
                'target_agent_slug' => $target->agentDescriptor->slug,
                'target_agent_runtime_class' => $target->agentDescriptor->runtime_class,
                'actor_name' => $request->actor->name,
                'capability' => $request->capability,
                'prompt' => $request->prompt,
                'target_context' => $request->targetContext,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'attempts' => 0,
                'status' => AgentDelegation::STATUS_PENDING,
                'requested_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = AgentDelegation::query()
                ->where('organization_id', $source->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameRequest($existing, $source, $target, $request);

            return $existing;
        }
    }

    private function response(
        AgentDelegationRequest $request,
        AgentAssignment $source,
        AgentAssignment $target,
        AgentDelegation $delegation,
        ?AgentExecution $execution = null,
    ): AgentDelegationResponse {
        $targetContext = $delegation->target_context;
        if ($execution === null) {
            $execution = $delegation->targetAgentExecution;
        }

        if (is_string($targetContext)) {
            $targetContext = json_decode($targetContext, true);
        }

        return new AgentDelegationResponse(
            actor: $request->actor,
            sourceAssignment: $source,
            sourceDescriptor: $source->agentDescriptor,
            targetAssignment: $target,
            targetDescriptor: $target->agentDescriptor,
            capability: $delegation->capability,
            prompt: $delegation->prompt,
            targetContext: is_array($targetContext) ? $targetContext : [],
            correlationId: $delegation->correlation_id,
            delegation: $delegation,
            execution: $execution,
        );
    }

    private function assertSameRequest(
        AgentDelegation $delegation,
        AgentAssignment $source,
        AgentAssignment $target,
        AgentDelegationRequest $request,
    ): void {
        $storedContext = $delegation->target_context;
        while (is_string($storedContext)) {
            $decoded = json_decode($storedContext, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $storedContext = [];
                break;
            } $storedContext = $decoded;
        }

        if (
            $delegation->source_agent_assignment_id !== $source->getKey()
            || $delegation->target_agent_assignment_id !== $target->getKey()
            || $delegation->capability !== $request->capability
            || $delegation->prompt !== $request->prompt
            || $delegation->actor_id !== $request->actor->getKey()
            || $delegation->parent_agent_execution_id !== $request->parentExecution?->getKey()
            || $storedContext !== $request->targetContext
        ) {
            throw new AuthorizationException('The idempotency key is already bound to a different delegation request.');
        }
    }

    private function resolveIdempotencyKey(AgentDelegationRequest $request): string
    {
        if ($request->idempotencyKey === null || trim($request->idempotencyKey) === '') {
            throw new AuthorizationException('An idempotency key is required for Agent delegation.');
        }

        return trim($request->idempotencyKey);
    }

    private function assertEnabled(AgentAssignment $assignment, string $role): void
    {
        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException(sprintf('The %s Agent assignment is disabled.', $role));
        }

        if ($assignment->enterprise_id !== null
            && (! $assignment->enterprise instanceof Enterprise
                || $assignment->enterprise->organization_id !== $assignment->organization_id)
        ) {
            throw new AuthorizationException(sprintf(
                'The %s Agent assignment Enterprise does not belong to its organization.',
                $role,
            ));
        }
    }

    private function resolveTarget(AgentAssignment $source, string $slug): AgentAssignment
    {
        $target = AgentAssignment::query()
            ->with(['agentDescriptor', 'organization', 'enterprise'])
            ->whereHas('agentDescriptor', fn ($query) => $query->where('slug', $slug))
            ->where('organization_id', $source->organization_id)
            ->where('enterprise_id', $source->enterprise_id)
            ->first();

        if ($target !== null) {
            return $target;
        }

        $foreignTargetExists = AgentAssignment::query()
            ->whereHas('agentDescriptor', fn ($query) => $query->where('slug', $slug))
            ->exists();

        if ($foreignTargetExists) {
            throw new AuthorizationException('The target Agent is outside the source Agent scope.');
        }

        throw new AuthorizationException('The target Agent is not assigned in the source Agent scope.');
    }
}