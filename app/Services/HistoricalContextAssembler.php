<?php

namespace App\Services;

use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class HistoricalContextAssembler
{
    private const HISTORY_LIMIT = 25;

    /** @param array<string, mixed> $targetContext */
    public function decisions(User $user, Enterprise $enterprise, array $targetContext = []): AgentContextSection
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $contextualFields = ['objective_id', 'strategy_id', 'plan_id', 'initiative_id', 'project_id', 'task_id', 'work_item_id'];

        $decisionQuery = Decision::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->when($targetContext !== [], function ($query) use ($targetContext, $contextualFields): void {
                $query->where(function ($query) use ($targetContext, $contextualFields): void {
                    foreach ($contextualFields as $field) {
                        if (array_key_exists($field, $targetContext) && $targetContext[$field] !== null) {
                            $query->orWhere($field, $targetContext[$field]);
                        }
                    }
                });
            })
            ->latest('decided_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $enterpriseDecisions = EnterpriseDecision::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->latest('decided_at')->latest('id')->limit(self::HISTORY_LIMIT)->get();

        $agentDecisions = AgentDecision::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->latest('decided_at')->latest('id')->limit(self::HISTORY_LIMIT)->get();

        return new AgentContextSection(
            name: 'decisions',
            data: [
                'decision_records' => $decisionQuery->map(fn (Decision $decision): array => $this->decision($decision))->all(),
                'enterprise_decisions' => $enterpriseDecisions->map(fn (EnterpriseDecision $decision): array => $this->enterpriseDecision($decision))->all(),
                'agent_decisions' => $agentDecisions->map(fn (AgentDecision $decision): array => $this->agentDecision($decision))->all(),
            ],
            source: self::class,
            scope: $this->scope($enterprise),
            relevance: $targetContext === [] ? 'Bounded recent Enterprise decision history' : 'Bounded decision history matching the execution target context',
        );
    }

    public function executionHistory(User $user, Enterprise $enterprise, ?AgentAssignment $assignment = null): AgentContextSection
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        if ($assignment !== null && ($assignment->organization_id !== $enterprise->organization_id || $assignment->enterprise_id !== $enterprise->getKey())) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Agent assignment is outside the Enterprise scope.');
        }

        $executions = AgentExecution::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->when($assignment !== null, fn ($query) => $query->where('agent_assignment_id', $assignment->getKey()))
            ->latest('requested_at')->latest('id')->limit(self::HISTORY_LIMIT)->get();

        $executionIds = $executions->modelKeys();

        $delegations = AgentDelegation::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->when($assignment !== null, function ($query) use ($assignment, $executionIds): void {
                $query->where(function ($query) use ($assignment, $executionIds): void {
                    $query->where('source_agent_assignment_id', $assignment->getKey())
                        ->orWhere('target_agent_assignment_id', $assignment->getKey());
                    if ($executionIds !== []) {
                        $query->orWhereIn('parent_agent_execution_id', $executionIds)->orWhereIn('target_agent_execution_id', $executionIds);
                    }
                });
            })
            ->latest('requested_at')->latest('id')->limit(self::HISTORY_LIMIT)->get();

        $delegationIds = $delegations->modelKeys();

        $approvals = ApprovalRequest::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->where(function ($query) use ($executionIds, $delegationIds): void {
                if ($executionIds !== []) {
                    $query->whereIn('agent_execution_id', $executionIds);
                }
                if ($delegationIds !== []) {
                    $query->orWhereIn('agent_delegation_id', $delegationIds);
                }
            })
            ->latest('requested_at')->latest('id')->limit(self::HISTORY_LIMIT)->get();

        return new AgentContextSection(
            name: 'execution_history',
            data: [
                'executions' => $executions->map(fn (AgentExecution $execution): array => $this->execution($execution))->all(),
                'delegations' => $delegations->map(fn (AgentDelegation $delegation): array => $this->delegation($delegation))->all(),
                'approvals' => $approvals->map(fn (ApprovalRequest $approval): array => $this->approval($approval))->all(),
            ],
            source: self::class,
            scope: $this->scope($enterprise),
            relevance: $assignment === null ? 'Bounded recent Enterprise execution history' : 'Bounded execution history for the current Agent assignment',
        );
    }

    /** @return array<string, mixed> */
    private function decision(Decision $decision): array
    {
        return [
            'id' => $decision->getKey(), 'enterprise_id' => $decision->enterprise_id, 'type' => $decision->type,
            'actor_id' => $decision->actor_id, 'actor_name' => $decision->actor_name,
            'objective_id' => $decision->objective_id, 'strategy_id' => $decision->strategy_id, 'plan_id' => $decision->plan_id,
            'initiative_id' => $decision->initiative_id, 'project_id' => $decision->project_id, 'task_id' => $decision->task_id,
            'work_item_id' => $decision->work_item_id, 'title' => $decision->title, 'summary' => $decision->summary,
            'rationale' => $decision->rationale, 'decided_at' => $this->timestamp($decision->decided_at),
        ];
    }

    /** @return array<string, mixed> */
    private function enterpriseDecision(EnterpriseDecision $decision): array
    {
        return [
            'id' => $decision->getKey(), 'enterprise_id' => $decision->enterprise_id,
            'actor_id' => $decision->actor_id, 'actor_name' => $decision->actor_name,
            'title' => $decision->title, 'summary' => $decision->summary, 'rationale' => $decision->rationale,
            'decided_at' => $this->timestamp($decision->decided_at),
        ];
    }

    /** @return array<string, mixed> */
    private function agentDecision(AgentDecision $decision): array
    {
        return [
            'id' => $decision->getKey(), 'organization_id' => $decision->organization_id, 'enterprise_id' => $decision->enterprise_id,
            'execution_id' => $decision->execution_id, 'agent_descriptor_id' => $decision->agent_descriptor_id, 'actor_id' => $decision->actor_id,
            'organization_name' => $decision->organization_name, 'enterprise_name' => $decision->enterprise_name,
            'agent_slug' => $decision->agent_slug, 'agent_runtime_class' => $decision->agent_runtime_class, 'actor_name' => $decision->actor_name,
            'title' => $decision->title, 'summary' => $decision->summary, 'rationale' => $decision->rationale,
            'decided_at' => $this->timestamp($decision->decided_at),
        ];
    }

    /** @return array<string, mixed> */
    private function execution(AgentExecution $execution): array
    {
        return [
            'id' => $execution->getKey(), 'organization_id' => $execution->organization_id, 'enterprise_id' => $execution->enterprise_id,
            'agent_descriptor_id' => $execution->agent_descriptor_id, 'agent_assignment_id' => $execution->agent_assignment_id, 'actor_id' => $execution->actor_id,
            'organization_name' => $execution->organization_name, 'enterprise_name' => $execution->enterprise_name,
            'agent_slug' => $execution->agent_slug, 'agent_runtime_class' => $execution->agent_runtime_class, 'actor_name' => $execution->actor_name,
            'status' => $execution->status, 'requested_at' => $this->timestamp($execution->requested_at),
            'started_at' => $this->timestamp($execution->started_at), 'completed_at' => $this->timestamp($execution->completed_at),
            'failure_reason' => $execution->failure_reason, 'provider' => $execution->provider,
            'external_execution_id' => $execution->external_execution_id, 'failure_code' => $execution->failure_code,
        ];
    }

    /** @return array<string, mixed> */
    private function delegation(AgentDelegation $delegation): array
    {
        return [
            'id' => $delegation->getKey(), 'organization_id' => $delegation->organization_id, 'enterprise_id' => $delegation->enterprise_id,
            'source_agent_assignment_id' => $delegation->source_agent_assignment_id, 'target_agent_assignment_id' => $delegation->target_agent_assignment_id,
            'parent_agent_execution_id' => $delegation->parent_agent_execution_id, 'target_agent_execution_id' => $delegation->target_agent_execution_id,
            'actor_id' => $delegation->actor_id, 'organization_name' => $delegation->organization_name, 'enterprise_name' => $delegation->enterprise_name,
            'source_agent_slug' => $delegation->source_agent_slug, 'source_agent_runtime_class' => $delegation->source_agent_runtime_class,
            'target_agent_slug' => $delegation->target_agent_slug, 'target_agent_runtime_class' => $delegation->target_agent_runtime_class,
            'actor_name' => $delegation->actor_name, 'capability' => $delegation->capability, 'prompt' => $delegation->prompt,
            'target_context' => $delegation->target_context, 'correlation_id' => $delegation->correlation_id, 'status' => $delegation->status,
            'attempts' => $delegation->attempts, 'requested_at' => $this->timestamp($delegation->requested_at),
            'started_at' => $this->timestamp($delegation->started_at), 'completed_at' => $this->timestamp($delegation->completed_at),
            'failure_reason' => $delegation->failure_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function approval(ApprovalRequest $approval): array
    {
        return [
            'id' => $approval->getKey(), 'organization_id' => $approval->organization_id, 'enterprise_id' => $approval->enterprise_id,
            'agent_assignment_id' => $approval->agent_assignment_id, 'agent_execution_id' => $approval->agent_execution_id,
            'agent_delegation_id' => $approval->agent_delegation_id, 'actor_id' => $approval->actor_id, 'approver_id' => $approval->approver_id,
            'capability' => $approval->capability, 'target_context' => $approval->target_context,
            'organization_name' => $approval->organization_name, 'enterprise_name' => $approval->enterprise_name,
            'agent_slug' => $approval->agent_slug, 'agent_runtime_class' => $approval->agent_runtime_class,
            'actor_name' => $approval->actor_name, 'approver_name' => $approval->approver_name, 'status' => $approval->status,
            'requested_at' => $this->timestamp($approval->requested_at), 'expires_at' => $this->timestamp($approval->expires_at),
            'decided_at' => $this->timestamp($approval->decided_at), 'decision_reason' => $approval->decision_reason,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toISOString();
    }

    /** @return array{organization_id: int|string, enterprise_id: int|string} */
    private function scope(Enterprise $enterprise): array
    {
        return ['organization_id' => $enterprise->organization_id, 'enterprise_id' => $enterprise->getKey()];
    }
}
