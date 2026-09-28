<?php

namespace App\Services;

use App\Events\ApprovalGranted;
use App\Events\ApprovalRequested;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

class ApprovalRequestService
{
    public function __construct(private readonly ApprovalPolicyService $policies) {}

    /** @param array<string, mixed> $targetContext */
    public function request(
        User $actor,
        string $capability,
        AgentAssignment $assignment,
        ?AgentExecution $execution = null,
        array $targetContext = [],
        ?string $correlationId = null,
    ): ApprovalRequest {
        $requestedAt = now();
        $policy = $this->policies->snapshotFor($assignment, $capability);
        $requestHash = $this->requestHash($assignment, $capability, $execution, $targetContext);

        $request = ApprovalRequest::query()->create([
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
            'expires_at' => $requestedAt->copy()->addMinutes($policy['expires_in_minutes']),
            'policy_snapshot' => $policy,
            'policy_key' => $policy['policy_key'],
            'current_stage' => 0,
            'request_hash' => $requestHash,
        ]);

        if ($execution !== null) {
            app(AgentExecutionEventService::class)->dispatch(ApprovalRequested::class, $execution, provenance: [
                'approval_request_id' => $request->getKey(),
                'capability' => $capability,
            ], data: [
                'status' => $request->status,
                'policy_key' => $request->policy_key,
            ]);
        }

        return $request;
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $reason = null): ApprovalRequest
    {
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw new LogicException('Only a pending approval request can be decided.');
        }

        Gate::forUser($approver)->authorize('approve', $request);
        $this->policies->authorizeApprover($request, $approver);

        return DB::transaction(function () use ($request, $approver, $reason): ApprovalRequest {
            $request->refresh();

            if (! $request->isDecisionOpen()) {
                throw new LogicException('The approval request is no longer open for a decision.');
            }

            $stage = (int) $request->current_stage;

            if (ApprovalDecision::query()
                ->where('approval_request_id', $request->getKey())
                ->where('stage', $stage)
                ->where('actor_id', $approver->getKey())
                ->exists()
            ) {
                throw new LogicException('This approver has already decided the active approval stage.');
            }

            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'organization_id' => $request->organization_id,
                'enterprise_id' => $request->enterprise_id,
                'stage' => $stage,
                'decision' => ApprovalDecision::DECISION_APPROVED,
                'actor_id' => $approver->getKey(),
                'actor_name' => $approver->name,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $approvedCount = ApprovalDecision::query()
                ->where('approval_request_id', $request->getKey())
                ->where('stage', $stage)
                ->where('decision', ApprovalDecision::DECISION_APPROVED)
                ->count();

            if ($approvedCount >= $this->policies->requiredApprovals($request)) {
                if ($this->policies->hasNextStage($request)) {
                    $request->current_stage++;
                    $request->save();
                } else {
                    $request->approve($approver, $reason)->save();
                }
            }

            if ($request->status === ApprovalRequest::STATUS_APPROVED && $request->agent_execution_id !== null) {
                $execution = AgentExecution::query()->find($request->agent_execution_id);

                if ($execution instanceof AgentExecution) {
                    app(AgentExecutionEventService::class)->dispatch(ApprovalGranted::class, $execution, provenance: [
                        'approval_request_id' => $request->getKey(),
                        'capability' => $request->capability,
                    ], data: [
                        'approver_id' => $approver->getKey(),
                        'status' => $request->status,
                        'stage' => $stage,
                    ]);
                }
            }

            return $request->refresh();
        });
    }

    public function reject(ApprovalRequest $request, User $approver, ?string $reason = null): ApprovalRequest
    {
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw new LogicException('Only a pending approval request can be decided.');
        }

        Gate::forUser($approver)->authorize('reject', $request);
        $this->policies->authorizeApprover($request, $approver);

        return DB::transaction(function () use ($request, $approver, $reason): ApprovalRequest {
            $request->refresh();

            if (! $request->isDecisionOpen()) {
                throw new LogicException('The approval request is no longer open for a decision.');
            }

            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'organization_id' => $request->organization_id,
                'enterprise_id' => $request->enterprise_id,
                'stage' => $request->current_stage,
                'decision' => ApprovalDecision::DECISION_REJECTED,
                'actor_id' => $approver->getKey(),
                'actor_name' => $approver->name,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            return $request->reject($approver, $reason)->save() ? $request->refresh() : $request;
        });
    }

    public function cancel(ApprovalRequest $request, User $actor, ?string $reason = null): ApprovalRequest
    {
        Gate::forUser($actor)->authorize('cancel', $request);

        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $request->refresh();

            if (! $request->isDecisionOpen()) {
                throw new LogicException('The approval request is no longer open for cancellation.');
            }

            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'organization_id' => $request->organization_id,
                'enterprise_id' => $request->enterprise_id,
                'stage' => $request->current_stage,
                'decision' => ApprovalDecision::DECISION_CANCELLED,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $request->cancel($reason)->save();

            return $request->refresh();
        });
    }

    public function expire(ApprovalRequest $request): ApprovalRequest
    {
        $expiresAt = $request->expiresAt();

        if ($request->status !== ApprovalRequest::STATUS_PENDING || $expiresAt === null || $expiresAt->isFuture()) {
            throw new LogicException('The approval request is not ready to expire.');
        }

        return DB::transaction(function () use ($request): ApprovalRequest {
            $request->refresh();

            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'organization_id' => $request->organization_id,
                'enterprise_id' => $request->enterprise_id,
                'stage' => $request->current_stage,
                'decision' => ApprovalDecision::DECISION_EXPIRED,
                'actor_id' => null,
                'actor_name' => 'system',
                'reason' => 'Approval request expired.',
                'decided_at' => now(),
            ]);

            $request->expire()->save();

            return $request->refresh();
        });
    }

    public function markStale(ApprovalRequest $request, User $actor, string $reason): ApprovalRequest
    {
        Gate::forUser($actor)->authorize('cancel', $request);

        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $request->refresh();

            if (! $request->isDecisionOpen()) {
                throw new LogicException('Only an open approval request can become stale.');
            }

            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'organization_id' => $request->organization_id,
                'enterprise_id' => $request->enterprise_id,
                'stage' => $request->current_stage,
                'decision' => ApprovalDecision::DECISION_STALE,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $request->markStale($reason)->save();

            return $request->refresh();
        });
    }

    /** @param array<string, mixed> $targetContext */
    public function matches(
        ApprovalRequest $request,
        User $actor,
        AgentAssignment $assignment,
        string $capability,
        ?AgentExecution $execution = null,
        array $targetContext = [],
        ?AgentDelegation $delegation = null,
    ): bool {
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

        if (($request->agent_execution_id !== null) !== ($execution !== null)) {
            return false;
        }

        if ($execution !== null && $request->agent_execution_id !== $execution->getKey()) {
            return false;
        }

        if ($request->request_hash !== null
            && $request->request_hash !== $this->requestHash($assignment, $capability, $execution, $targetContext)
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

        return $this->normalizeContext($request->target_context ?? []) === $this->normalizeContext($targetContext);
    }

    public function bindToDelegation(ApprovalRequest $request, AgentDelegation $delegation): ApprovalRequest
    {
        if ($request->agent_delegation_id !== null) {
            if ($request->agent_delegation_id !== $delegation->getKey()) {
                throw new LogicException('Approval request is already bound to another delegation.');
            }

            return $request;
        }

        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw new LogicException('An approved or rejected approval request cannot be newly bound to a delegation.');
        }

        if ($request->organization_id !== $delegation->organization_id
            || $request->enterprise_id !== $delegation->enterprise_id
            || $request->actor_id !== $delegation->actor_id
        ) {
            throw new LogicException('Approval request does not match the delegation it is intended to authorize.');
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
            throw new LogicException('Approval request assignment does not belong to the delegation.');
        }

        if ($this->normalizeContext($request->target_context ?? []) !== $this->normalizeContext($expectedContext)) {
            throw new LogicException('Approval request target context does not match the delegation it is intended to authorize.');
        }

        if ($request->agent_assignment_id === $delegation->target_agent_assignment_id
            && $request->capability !== $delegation->capability
        ) {
            throw new LogicException('Target approval capability does not match the delegation capability.');
        }

        $request->agent_delegation_id = $delegation->getKey();
        $request->save();

        return $request->refresh();
    }

    public function consumeForDelegation(ApprovalRequest $request, AgentDelegation $delegation): ApprovalRequest
    {
        if ($request->agent_delegation_id !== $delegation->getKey()) {
            throw new LogicException('Approval request is not bound to this delegation.');
        }

        if ($request->consumed_agent_delegation_id !== null
            && $request->consumed_agent_delegation_id !== $delegation->getKey()
        ) {
            throw new LogicException('Approval request has already been consumed by another delegation.');
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
            throw new LogicException('Approval request has already been consumed by another execution.');
        }

        if ($request->consumed_agent_execution_id === null) {
            $request->consumed_agent_execution_id = $execution->getKey();
            $request->save();
        }

        return $request->refresh();
    }

    /** @param array<string, mixed> $targetContext */
    private function requestHash(
        AgentAssignment $assignment,
        string $capability,
        ?AgentExecution $execution,
        array $targetContext,
    ): string {
        return hash('sha256', json_encode($this->normalizeContext([
            'assignment_id' => $assignment->getKey(),
            'capability' => $capability,
            'execution_id' => $execution?->getKey(),
            'target_context' => $targetContext,
        ]), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed>|string|null $context */
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