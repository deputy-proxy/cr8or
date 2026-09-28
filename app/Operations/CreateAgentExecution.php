<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\AgentExecutionRequest;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;
use App\Services\AgentExecutionService;
use Illuminate\Auth\Access\AuthorizationException;

final class CreateAgentExecution implements Operation
{
    public function __construct(private readonly AgentExecutionService $executions, private readonly AgentExecutionResourceService $resources) {}

    public function execute(User $actor, array $input): mixed
    {
        $assignment = $input['assignment'] ?? AgentAssignment::query()->with(['agentDescriptor', 'organization', 'enterprise'])->findOrFail((int) $input['agent_assignment_id']);
        if (! in_array($assignment->status, [AgentAssignment::STATUS_READY, AgentAssignment::STATUS_RUNNING], true)) {
            throw new AuthorizationException('Agent execution requires an Assignment in ready or running state.');
        }

        $execution = $this->executions->queue(new AgentExecutionRequest(
            actor: $actor, assignment: $assignment, prompt: (string) $input['prompt'],
            targetContext: is_array($input['target_context'] ?? null) ? $input['target_context'] : [],
            expertSlugs: array_values(array_filter($input['expert_slugs'] ?? [], 'is_string')),
            options: is_array($input['options'] ?? null) ? $input['options'] : [],
            correlationId: $input['correlation_id'] ?? null, idempotencyKey: $input['idempotency_key'] ?? null,
        ));
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        return $this->resources->get($actor, $enterprise, $execution);
    }
}
