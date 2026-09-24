<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ApprovalRequestService
{
    /** @param array<string, mixed> $targetContext */
    public function request(User $actor, string $capability, AgentAssignment $assignment, ?AgentExecution $execution = null, array $targetContext = [], ?string $correlationId = null): ApprovalRequest
    {
        $requestedAt = now();

        return ApprovalRequest::query()->create([
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $assignment->enterprise_id,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution?->getKey(),
            'actor_id' => $actor->getKey(),
            'correlation_id' => $correlationId ?? $execution?->correlation_id,
            'capability' => $capability,
            'target_context' => $targetContext,
            'organization_name' => $assignment->organization->name,
            'enterprise_name' => $assignment->enterprise?->name,
            'agent_slug' => $assignment->agentDescriptor->slug,
            'agent_runtime_class' => $assignment->agentDescriptor->runtime_class,
            'actor_name' => $actor->name,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_at' => $requestedAt,
            'expires_at' => $requestedAt->copy()->addHour(),
        ]);
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $reason = null): ApprovalRequest
    {
        Gate::forUser($approver)->authorize('approve', $request);
        $request->approve($approver, $reason)->save();

        return $request;
    }

    public function reject(ApprovalRequest $request, User $approver, ?string $reason = null): ApprovalRequest
    {
        Gate::forUser($approver)->authorize('reject', $request);
        $request->reject($approver, $reason)->save();

        return $request;
    }

    /** @param array<string, mixed> $targetContext */
    public function matches(ApprovalRequest $request, User $actor, AgentAssignment $assignment, string $capability, ?AgentExecution $execution = null, array $targetContext = [], ?AgentDelegation $delegation = null): bool
    {
        if (! $request->isValid() || $request->actor_id !== $actor->getKey()) {
            return false;
        }

        if ($request->organization_id !== $assignment->organization_id
            || $request->enterprise_id !== $assignment->enterprise_id
            || $request->agent_assignment_id !== $assignment->getKey()
            || $request->capability !== $capability
        ) {
            return false;
        }

        if ($delegation !== null) {
            $assignmentMatchesDelegation = $request->agent_assignment_id === $delegation->source_agent_assignment_id
                || $request->agent_assignment_id === $delegation->target_agent_assignment_id;

            if ($request->agent_delegation_id !== $delegation->getKey()
                || ! $assignmentMatchesDelegation
                || $delegation->organization_id !== $assignment->organization_id
                || $delegation->enterprise_id !== $assignment->enterprise_id
                || $delegation->actor_id !== $actor->getKey()
            ) {
                return false;
            }
        } elseif ($request->agent_delegation_id !== null) {
            return false;
        }

        if ($execution !== null && $request->agent_execution_id !== null && $request->agent_execution_id !== $execution->getKey()) {
            return false;
        }

        return $this->normalizeContext($request->target_context ?? []) === $this->normalizeContext($targetContext);
    }

    public function bindToDelegation(ApprovalRequest $request, AgentDelegation $delegation): ApprovalRequest
    {
        if ($request->agent_delegation_id !== null) {
            if ($request->agent_delegation_id !== $delegation->getKey()) {
                throw new \LogicException('Approval request is already bound to another delegation.');
            }

            return $request;
        }

        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw new \LogicException('An approved or rejected approval request cannot be newly bound to a delegation.');
        }

        if ($request->organization_id !== $delegation->organization_id
            || $request->enterprise_id !== $delegation->enterprise_id
            || $request->actor_id !== $delegation->actor_id
        ) {
            throw new \LogicException('Approval request does not match the delegation it is intended to authorize.');
        }

        $delegationContext = $this->normalizeContext($delegation->target_context ?? []);
        $expectedContext = $request->agent_assignment_id === $delegation->source_agent_assignment_id
            ? array_merge($delegationContext, [
                'target_agent_slug' => $delegation->target_agent_slug,
                'target_capability' => $delegation->capability,
            ])
            : $delegationContext;

        if ($request->agent_assignment_id !== $delegation->source_agent_assignment_id
            && $request->agent_assignment_id !== $delegation->target_agent_assignment_id
        ) {
            throw new \LogicException('Approval request assignment does not belong to the delegation.');
        }

        if ($this->normalizeContext($request->target_context ?? []) !== $this->normalizeContext($expectedContext)) {
            throw new \LogicException('Approval request target context does not match the delegation it is intended to authorize.');
        }

        if ($request->agent_assignment_id === $delegation->target_agent_assignment_id
            && $request->capability !== $delegation->capability
        ) {
            throw new \LogicException('Target approval capability does not match the delegation capability.');
        }

        $request->agent_delegation_id = $delegation->getKey();
        $request->save();

        return $request->refresh();
    }

    public function consumeForDelegation(ApprovalRequest $request, AgentDelegation $delegation): ApprovalRequest
    {
        if ($request->agent_delegation_id !== $delegation->getKey()) {
            throw new \LogicException('Approval request is not bound to this delegation.');
        }

        if ($request->consumed_agent_delegation_id !== null
            && $request->consumed_agent_delegation_id !== $delegation->getKey()
        ) {
            throw new \LogicException('Approval request has already been consumed by another delegation.');
        }

        if ($request->consumed_agent_delegation_id === null) {
            $request->consumed_agent_delegation_id = $delegation->getKey();
            $request->save();
        }

        return $request->refresh();
    }

    public function consume(ApprovalRequest $request, AgentExecution $execution): ApprovalRequest
    {
        if ($request->consumed_agent_execution_id !== null
            && $request->consumed_agent_execution_id !== $execution->getKey()
        ) {
            throw new \LogicException('Approval request has already been consumed by another execution.');
        }

        if ($request->consumed_agent_execution_id === null) {
            $request->consumed_agent_execution_id = $execution->getKey();
            $request->save();
        }

        return $request->refresh();
    }

    /**
     * @param  array<string, mixed>|string|null  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array|string|null $context): array
    {
        while (is_string($context)) {
            $decoded = json_decode($context, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            $context = $decoded;
        }

        $context ??= [];
        if (! is_array($context)) {
            return [];
        }
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->normalizeContext($value);
            }
        }

        if ($context !== [] && array_keys($context) !== range(0, count($context) - 1)) {
            ksort($context);
        }

        return $context;
    }
}