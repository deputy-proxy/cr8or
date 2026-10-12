<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\ApprovalRequestService;
use App\Services\McpCapabilityAuthorizer;

final class ApprovalRequestCreate implements Operation
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed
    {
        $assignment = AgentAssignment::query()
            ->with(['agentDescriptor', 'organization', 'enterprise'])
            ->findOrFail((int) ($input['agent_assignment_id'] ?? 0));
        $execution = isset($input['agent_execution_id'])
            ? AgentExecution::query()->findOrFail((int) $input['agent_execution_id'])
            : null;

        app(McpCapabilityAuthorizer::class)->authorizeApprovalRequest(
            $actor,
            $assignment,
            $execution,
            (string) ($input['capability'] ?? ''),
            isset($input['expert_slug']) ? (string) $input['expert_slug'] : null,
        );

        return app(ApprovalRequestService::class)->request(
            $actor,
            (string) $input['capability'],
            $assignment,
            $execution,
            is_array($input['target_context'] ?? null) ? $input['target_context'] : [],
            isset($input['correlation_id']) ? (string) $input['correlation_id'] : null,
            (string) $input['expert_slug'],
        );
    }
}