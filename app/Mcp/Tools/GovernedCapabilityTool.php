<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityDefinition;
use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\CapabilityInvocationService;
use Illuminate\Database\Eloquent\Model;

abstract class GovernedCapabilityTool extends AuthorizedTool
{
    protected function definition(CapabilityRegistry $registry): CapabilityDefinition
    {
        return $registry->forTool(static::class);
    }

    protected function capability(CapabilityRegistry $registry): string
    {
        return $this->definition($registry)->key;
    }

    /** @param array<string, mixed> $input */
    protected function executeCapability(CapabilityRegistry $registry, User $actor, array $input): mixed
    {
        $enterprise = $this->resolveEnterprise($input);
        $assignment = $this->resolveAssignment($input);
        $execution = $this->resolveExecution($input);
        $approval = $this->resolveApproval($input);

        $targetContext = isset($input['target_context']) && is_array($input['target_context'])
            ? $input['target_context']
            : array_filter([
                'enterprise_id' => $enterprise->getKey(),
                'agent_assignment_id' => $assignment?->getKey(),
                'agent_execution_id' => $execution?->getKey(),
                'approval_request_id' => $approval?->getKey(),
            ], static fn (mixed $value): bool => $value !== null);

        return app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
            capability: $this->capability($registry),
            actor: $actor,
            enterprise: $enterprise,
            targetContext: $targetContext,
            inputPayload: $input,
            assignment: $assignment,
            execution: $execution,
            approval: $approval,
            correlationId: isset($input['correlation_id']) && is_string($input['correlation_id']) ? $input['correlation_id'] : null,
            idempotencyKey: isset($input['idempotency_key']) && is_string($input['idempotency_key']) ? $input['idempotency_key'] : null,
        ))['raw_result'] ?? null;
    }

    /** @param array<string, mixed> $input */
    private function resolveEnterprise(array $input): Enterprise
    {
        if (($input['enterprise'] ?? null) instanceof Enterprise) {
            return $input['enterprise'];
        }

        if (isset($input['enterprise_id'])) {
            return Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        }

        foreach (['source_assignment', 'assignment', 'execution', 'approval', 'work_item', 'content_item', 'objective', 'strategy', 'campaign', 'channel', 'audience', 'project', 'social_account'] as $key) {
            $value = $input[$key] ?? null;

            if ($value instanceof Enterprise) {
                return $value;
            }

            if ($value instanceof AgentAssignment && $value->getAttribute('enterprise') instanceof Enterprise) {
                return $value->getAttribute('enterprise');
            }

            if ($value instanceof AgentExecution && $value->getAttribute('enterprise') instanceof Enterprise) {
                return $value->getAttribute('enterprise');
            }

            if (! $value instanceof Model) {
                continue;
            }

            if (method_exists($value, 'enterprise') && $value->getAttribute('enterprise') instanceof Enterprise) {
                return $value->getAttribute('enterprise');
            }

            foreach (['objective', 'project', 'campaign', 'contentSeries', 'channel', 'audience'] as $relation) {
                if (! method_exists($value, $relation)) {
                    continue;
                }

                $related = $value->getAttribute($relation);

                if ($related instanceof Enterprise) {
                    return $related;
                }

                if ($related instanceof Model && method_exists($related, 'enterprise') && $related->getAttribute('enterprise') instanceof Enterprise) {
                    return $related->getAttribute('enterprise');
                }
            }
        }

        throw new \InvalidArgumentException('A governed Capability invocation requires an Enterprise context.');
    }

    /** @param array<string, mixed> $input */
    private function resolveAssignment(array $input): ?AgentAssignment
    {
        if (($input['assignment'] ?? null) instanceof AgentAssignment) {
            return $input['assignment'];
        }

        return isset($input['agent_assignment_id'])
            ? AgentAssignment::query()->with(['agentDescriptor', 'organization', 'enterprise'])->findOrFail((int) $input['agent_assignment_id'])
            : null;
    }

    /** @param array<string, mixed> $input */
    private function resolveExecution(array $input): ?AgentExecution
    {
        if (($input['execution'] ?? null) instanceof AgentExecution) {
            return $input['execution'];
        }

        return isset($input['agent_execution_id'])
            ? AgentExecution::query()->findOrFail((int) $input['agent_execution_id'])
            : null;
    }

    /** @param array<string, mixed> $input */
    private function resolveApproval(array $input): ?ApprovalRequest
    {
        if (($input['approval'] ?? null) instanceof ApprovalRequest) {
            return $input['approval'];
        }

        return isset($input['approval_request_id'])
            ? ApprovalRequest::query()->findOrFail((int) $input['approval_request_id'])
            : null;
    }
}