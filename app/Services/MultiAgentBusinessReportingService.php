<?php

namespace App\Services;

use App\Models\AgentDecision;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\FinancialReport;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class MultiAgentBusinessReportingService
{
    /** @return array<string, mixed> */
    public function generate(User $user, Enterprise $enterprise): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $executions = AgentExecution::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();
        $decisions = AgentDecision::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();
        $delegations = AgentDelegation::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();
        $approvals = ApprovalRequest::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();
        $workflows = Workflow::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();
        $financialReports = FinancialReport::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();
        $businessHealthResults = BusinessHealthResult::query()->where('enterprise_id', $enterprise->getKey())->orderBy('id')->get();

        $financialReportResults = [];
        foreach ($financialReports as $report) {
            $financialReportResults[] = [
                'id' => $report->getKey(),
                'generated_at' => Carbon::parse((string) $report->generated_at)->toISOString(),
                'currency' => $report->currency,
            ];
        }

        $healthResults = [];
        foreach ($businessHealthResults as $result) {
            $healthResults[] = [
                'id' => $result->getKey(),
                'financial_report_id' => $result->financial_report_id,
                'health_status' => $result->health_status,
                'evaluated_at' => Carbon::parse((string) $result->evaluated_at)->toISOString(),
            ];
        }

        return [
            'enterprise' => [
                'id' => $enterprise->getKey(),
                'name' => $enterprise->name,
                'slug' => $enterprise->slug,
            ],
            'execution_summary' => $this->statusSummary($executions->pluck('status')->all(), [
                AgentExecution::STATUS_REQUESTED,
                AgentExecution::STATUS_EXECUTING,
                AgentExecution::STATUS_SUCCEEDED,
                AgentExecution::STATUS_FAILED,
            ]),
            'delegation_summary' => $this->statusSummary($delegations->pluck('status')->all(), [
                AgentDelegation::STATUS_PENDING,
                AgentDelegation::STATUS_RUNNING,
                AgentDelegation::STATUS_SUCCEEDED,
                AgentDelegation::STATUS_FAILED,
            ]),
            'workflow_summary' => $this->statusSummary($workflows->pluck('status')->all(), [
                Workflow::STATUS_PENDING,
                Workflow::STATUS_RUNNING,
                Workflow::STATUS_SUCCEEDED,
                Workflow::STATUS_FAILED,
            ]),
            'approval_summary' => $this->statusSummary($approvals->pluck('status')->all(), [
                ApprovalRequest::STATUS_PENDING,
                ApprovalRequest::STATUS_APPROVED,
                ApprovalRequest::STATUS_REJECTED,
            ]),
            'agent_activity' => $this->agentActivity($executions, $delegations),
            'failed_operations' => $this->failedOperations($executions, $delegations, $workflows),
            'approval_outcomes' => $this->approvalOutcomes($approvals),
            'business_results' => [
                'financial_reports' => $financialReportResults,
                'business_health_results' => $healthResults,
            ],
            'decision_count' => $decisions->count(),
        ];
    }

    /**
     * @param  array<int, mixed>  $statuses
     * @param  list<string>  $knownStatuses
     * @return array<string, int>
     */
    private function statusSummary(array $statuses, array $knownStatuses): array
    {
        $summary = array_fill_keys($knownStatuses, 0);

        foreach ($statuses as $status) {
            $status = (string) $status;
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary + ['total' => count($statuses)];
    }

    /**
     * @param  iterable<int, AgentExecution>  $executions
     * @param  iterable<int, AgentDelegation>  $delegations
     * @return list<array<string, int|string|null>>
     */
    private function agentActivity(iterable $executions, iterable $delegations): array
    {
        /** @var array<string, array<string, int|string|null>> $activity */
        $activity = [];

        foreach ($executions as $execution) {
            $slug = $execution->agent_slug ?? 'unknown';
            $activity[$slug] ??= $this->emptyAgentActivity($slug, $execution->agent_runtime_class);
            $activity[$slug]['execution_count']++;
            $activity[$slug]['executions_succeeded'] += (int) ($execution->status === AgentExecution::STATUS_SUCCEEDED);
            $activity[$slug]['executions_failed'] += (int) ($execution->status === AgentExecution::STATUS_FAILED);
        }

        foreach ($delegations as $delegation) {
            $source = $delegation->source_agent_slug;
            $target = $delegation->target_agent_slug;

            $activity[$source] ??= $this->emptyAgentActivity($source, $delegation->source_agent_runtime_class);
            $activity[$target] ??= $this->emptyAgentActivity($target, $delegation->target_agent_runtime_class);

            $activity[$source]['delegations_out']++;
            $activity[$target]['delegations_in']++;
            $activity[$source]['delegations_succeeded'] += (int) ($delegation->status === AgentDelegation::STATUS_SUCCEEDED);
            $activity[$source]['delegations_failed'] += (int) ($delegation->status === AgentDelegation::STATUS_FAILED);
        }

        ksort($activity);

        $result = [];
        foreach ($activity as $entry) {
            $result[] = $entry;
        }

        return $result;
    }

    /** @return array<string, int|string|null> */
    private function emptyAgentActivity(string $slug, ?string $runtimeClass): array
    {
        return [
            'agent_slug' => $slug,
            'runtime_class' => $runtimeClass,
            'execution_count' => 0,
            'executions_succeeded' => 0,
            'executions_failed' => 0,
            'delegations_out' => 0,
            'delegations_in' => 0,
            'delegations_succeeded' => 0,
            'delegations_failed' => 0,
        ];
    }

    /**
     * @param  iterable<int, AgentExecution>  $executions
     * @param  iterable<int, AgentDelegation>  $delegations
     * @param  iterable<int, Workflow>  $workflows
     * @return list<array<string, mixed>>
     */
    private function failedOperations(iterable $executions, iterable $delegations, iterable $workflows): array
    {
        $operations = [];

        foreach ($executions as $execution) {
            if ($execution->status !== AgentExecution::STATUS_FAILED) {
                continue;
            }

            $operations[] = [
                'type' => 'agent_execution',
                'id' => $execution->getKey(),
                'agent_slug' => $execution->agent_slug,
                'status' => AgentExecution::STATUS_FAILED,
                'failure_reason' => $execution->failure_reason,
                'completed_at' => $execution->completed_at === null
                    ? null
                    : Carbon::parse((string) $execution->completed_at)->toISOString(),
            ];
        }

        foreach ($delegations as $delegation) {
            if ($delegation->status !== AgentDelegation::STATUS_FAILED) {
                continue;
            }

            $operations[] = [
                'type' => 'agent_delegation',
                'id' => $delegation->getKey(),
                'agent_slug' => $delegation->target_agent_slug,
                'status' => AgentDelegation::STATUS_FAILED,
                'failure_reason' => $delegation->failure_reason,
                'completed_at' => $delegation->completed_at === null
                    ? null
                    : Carbon::parse((string) $delegation->completed_at)->toISOString(),
            ];
        }

        foreach ($workflows as $workflow) {
            if ($workflow->status !== Workflow::STATUS_FAILED) {
                continue;
            }

            $operations[] = [
                'type' => 'workflow',
                'id' => $workflow->getKey(),
                'agent_slug' => null,
                'status' => Workflow::STATUS_FAILED,
                'failure_reason' => null,
                'completed_at' => $workflow->updated_at === null
                    ? null
                    : Carbon::parse((string) $workflow->updated_at)->toISOString(),
            ];
        }

        usort($operations, static fn (array $left, array $right): int => [$left['type'], $left['id']] <=> [$right['type'], $right['id']]);

        return $operations;
    }

    /**
     * @param  iterable<int, ApprovalRequest>  $approvals
     * @return list<array<string, mixed>>
     */
    private function approvalOutcomes(iterable $approvals): array
    {
        $outcomes = [];

        foreach ($approvals as $approval) {
            $outcomes[] = [
                'id' => $approval->getKey(),
                'agent_slug' => $approval->agent_slug,
                'capability' => $approval->capability,
                'status' => $approval->status,
                'requested_at' => Carbon::parse((string) $approval->requested_at)->toISOString(),
                'decided_at' => $approval->decided_at === null
                    ? null
                    : Carbon::parse((string) $approval->decided_at)->toISOString(),
                'approver_name' => $approval->approver_name,
                'decision_reason' => $approval->decision_reason,
            ];
        }

        return $outcomes;
    }
}