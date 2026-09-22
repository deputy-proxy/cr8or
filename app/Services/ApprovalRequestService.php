<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ApprovalRequestService
{
    /** @param array<string, mixed> $targetContext */
    public function request(User $actor, string $capability, AgentAssignment $assignment, ?AgentExecution $execution = null, array $targetContext = []): ApprovalRequest
    {
        $requestedAt = now();

        return ApprovalRequest::query()->create([
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $assignment->enterprise_id,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution?->getKey(),
            'actor_id' => $actor->getKey(),
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
    public function matches(ApprovalRequest $request, User $actor, AgentAssignment $assignment, string $capability, ?AgentExecution $execution = null, array $targetContext = []): bool
    {
        if (! $request->isValid() || $request->actor_id !== $actor->getKey()) {
            return false;
        }

        if ($request->organization_id !== $assignment->organization_id
            || $request->enterprise_id !== $assignment->enterprise_id
            || $request->agent_assignment_id !== $assignment->getKey()
            || $request->capability !== $capability
            || $request->agent_execution_id !== $execution?->getKey()
        ) {
            return false;
        }

        return $this->normalizeContext($request->target_context ?? []) === $this->normalizeContext($targetContext);
    }

    /**
     * @param  array<string, mixed>|string|null  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array|string|null $context): array
    {
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        }

        $context ??= [];
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
