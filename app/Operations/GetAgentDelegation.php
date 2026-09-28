<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentDelegation;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class GetAgentDelegation implements Operation
{
    public function execute(User $actor, array $input): mixed
    {
        $enterprise = Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $delegation = AgentDelegation::query()->with(['sourceAgentAssignment.agentDescriptor', 'targetAgentAssignment.agentDescriptor', 'parentAgentExecution', 'targetAgentExecution'])
            ->where('enterprise_id', $enterprise->getKey())
            ->findOrFail((int) $input['agent_delegation_id']);
        if (! Gate::forUser($actor)->allows('view', $delegation)) {
            throw new AuthorizationException('The Agent delegation is outside the authorized organization scope.');
        }

        return $this->serialize($delegation);
    }

    /** @return array<string, mixed> */
    public function serialize(AgentDelegation $delegation): array
    {
        return Arr::only($delegation->toArray(), [
            'id', 'organization_id', 'enterprise_id', 'source_agent_assignment_id', 'target_agent_assignment_id',
            'parent_agent_execution_id', 'target_agent_execution_id', 'actor_id', 'source_agent_slug', 'target_agent_slug',
            'capability', 'prompt', 'target_context', 'correlation_id', 'idempotency_key', 'attempts', 'status',
            'requested_at', 'started_at', 'completed_at', 'failure_reason',
        ]);
    }
}