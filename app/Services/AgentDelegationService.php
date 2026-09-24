<?php

namespace App\Services;

use App\Data\AgentDelegationRequest;
use App\Data\AgentDelegationResponse;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class AgentDelegationService
{
    public const DELEGATION_CAPABILITY = 'agent.delegate';

    public function __construct(
        private readonly AgentCapabilityAuthorizer $capabilityAuthorizer,
        private readonly ExecutionCorrelationService $correlation,
    ) {}

    public function delegate(AgentDelegationRequest $request): AgentDelegationResponse
    {
        $source = $request->sourceAssignment->loadMissing([
            'agentDescriptor',
            'organization',
            'enterprise',
        ]);

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

        $delegationContext = array_merge($request->targetContext, [
            'target_agent_slug' => $target->agentDescriptor->slug,
            'target_capability' => $request->capability,
        ]);

        if (! $this->capabilityAuthorizer->allows(
            $source,
            self::DELEGATION_CAPABILITY,
            $source->organization,
            $source->enterprise,
            $request->actor,
            $request->sourceApproval,
            null,
            $delegationContext,
        )) {
            throw new AuthorizationException('The source Agent is not authorized to delegate this work.');
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
        )) {
            throw new AuthorizationException(sprintf(
                'The target Agent is not authorized for capability [%s].',
                $request->capability,
            ));
        }

        return new AgentDelegationResponse(
            actor: $request->actor,
            sourceAssignment: $source,
            sourceDescriptor: $source->agentDescriptor,
            targetAssignment: $target,
            targetDescriptor: $target->agentDescriptor,
            capability: $request->capability,
            prompt: $request->prompt,
            targetContext: $request->targetContext,
            correlationId: $correlationId,
        );
    }

    private function assertEnabled(AgentAssignment $assignment, string $role): void
    {
        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException(sprintf(
                'The %s Agent assignment is disabled.',
                $role,
            ));
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