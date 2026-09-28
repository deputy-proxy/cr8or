<?php

namespace App\Services;

use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\AgentExecutionEventRecord;
use App\Models\AgentExecutionStep;
use App\Models\ApprovalRequest;
use App\Models\IntegrationResult;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class AgentExecutionOperationsService
{
    /** @return array<string, mixed> */
    public function inspect(User $actor, AgentExecution $execution): array
    {
        Gate::forUser($actor)->authorize('view', $execution);
        $execution->loadMissing(['agentAssignment.agentDescriptor', 'steps']);

        return [
            'execution' => [
                'id' => $execution->getKey(),
                'status' => $execution->status,
                'agent_slug' => $execution->agent_slug,
                'actor_name' => $execution->actor_name,
                'organization_name' => $execution->organization_name,
                'enterprise_name' => $execution->enterprise_name,
                'current_step' => $execution->current_step,
                'max_steps' => $execution->max_steps,
                'retry_count' => $execution->retry_count,
                'max_retries' => $execution->max_retries,
                'requested_at' => optional($execution->requested_at)?->toISOString(),
                'started_at' => optional($execution->started_at)?->toISOString(),
                'completed_at' => optional($execution->completed_at)?->toISOString(),
                'state_reason' => $execution->state_reason,
                'failure_code' => $execution->failure_code,
                'failure_reason' => $execution->failure_reason,
                'correlation_id' => $execution->correlation_id,
                'agent_assignment_id' => $execution->agent_assignment_id,
                'parent_agent_execution_id' => AgentDelegation::query()->where('target_agent_execution_id', $execution->getKey())->value('parent_agent_execution_id'),
            ],
            'steps' => $execution->steps->map(fn (AgentExecutionStep $step): array => [
                'number' => $step->sequence,
                'type' => $step->type,
                'status' => $step->status,
                'started_at' => optional($step->started_at)?->toISOString(),
                'completed_at' => optional($step->completed_at)?->toISOString(),
            ])->values()->all(),
            'timeline' => AgentExecutionEventRecord::query()
                ->where('agent_execution_id', $execution->getKey())
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->map(fn (AgentExecutionEventRecord $event): array => [
                    'type' => class_basename($event->event_type),
                    'occurred_at' => optional($event->occurred_at)?->toISOString(),
                    'provenance' => $event->provenance ?? [],
                    'data' => $event->data ?? [],
                ])->values()->all(),
            'approvals' => ApprovalRequest::query()
                ->where('agent_execution_id', $execution->getKey())
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn (ApprovalRequest $approval): array => [
                    'id' => $approval->getKey(),
                    'capability' => $approval->capability,
                    'status' => $approval->status,
                    'requested_at' => optional($approval->requested_at)?->toISOString(),
                    'expires_at' => optional($approval->expires_at)?->toISOString(),
                ])->all(),
            'delegations' => AgentDelegation::query()
                ->where('parent_agent_execution_id', $execution->getKey())
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn (AgentDelegation $delegation): array => [
                    'id' => $delegation->getKey(),
                    'target_agent_slug' => $delegation->target_agent_slug,
                    'capability' => $delegation->capability,
                    'status' => $delegation->status,
                    'requested_at' => optional($delegation->requested_at)?->toISOString(),
                    'target_agent_execution_id' => $delegation->target_agent_execution_id,
                    'correlation_id' => $delegation->correlation_id,
                ])->all(),
            'integration_results' => IntegrationResult::query()
                ->where('correlation_id', $execution->correlation_id)
                ->where('enterprise_id', $execution->enterprise_id)
                ->orderByDesc('received_at')
                ->get()
                ->map(fn (IntegrationResult $result): array => [
                    'provider' => $result->provider,
                    'operation' => $result->operation,
                    'status' => $result->status,
                    'processing_status' => $result->processing_status,
                    'received_at' => optional($result->received_at)?->toISOString(),
                ])->all(),
        ];
    }
}