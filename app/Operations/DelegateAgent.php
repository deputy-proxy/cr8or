<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\AgentDelegationRequest;
use App\Models\AgentAssignment;
use App\Models\User;
use App\Services\AgentDelegationService;

final class DelegateAgent implements Operation
{
    public function __construct(private readonly AgentDelegationService $delegations) {}

    public function execute(User $actor, array $input): mixed
    {
        return $this->delegations->delegate(new AgentDelegationRequest(
            actor: $actor,
            sourceAssignment: $input['source_assignment'] instanceof AgentAssignment
                ? $input['source_assignment']
                : AgentAssignment::query()->with(['agentDescriptor', 'organization', 'enterprise'])->findOrFail((int) $input['source_agent_assignment_id']),
            targetAgentSlug: (string) $input['target_agent_slug'],
            capability: (string) $input['capability'],
            prompt: (string) $input['prompt'],
            targetContext: $input['target_context'] ?? [],
            sourceApproval: $input['source_approval'] ?? null,
            targetApproval: $input['target_approval'] ?? null,
            correlationId: $input['correlation_id'] ?? null,
            idempotencyKey: (string) $input['idempotency_key'],
            parentExecution: $input['parent_execution'] ?? null,
        ));
    }
}
