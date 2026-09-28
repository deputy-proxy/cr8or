<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class AgentExecutionHealthService
{
    /** @return array<string, mixed> */
    public function summarize(User $actor, Enterprise $enterprise, ?Carbon $now = null): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $now ??= Carbon::now();
        $executions = AgentExecution::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->get();

        $stuck = $this->stuckExecutions($executions, $now);

        return [
            'enterprise_id' => $enterprise->getKey(),
            'generated_at' => $now->toISOString(),
            'counts' => [
                'total' => $executions->count(),
                'requested' => $executions->where('status', AgentExecution::STATUS_REQUESTED)->count(),
                'running' => $executions->whereIn('status', [
                    AgentExecution::STATUS_REASONING,
                    AgentExecution::STATUS_EXECUTING,
                ])->count(),
                'paused' => $executions->where('status', AgentExecution::STATUS_PAUSED)->count(),
                'waiting_for_input' => $executions->where('status', AgentExecution::STATUS_WAITING_FOR_INPUT)->count(),
                'waiting_for_approval' => $executions->where('status', AgentExecution::STATUS_WAITING_FOR_APPROVAL)->count(),
                'failed' => $executions->where('status', AgentExecution::STATUS_FAILED)->count(),
                'retried' => $executions->where('retry_count', '>', 0)->count(),
                'stuck' => count($stuck),
            ],
            'stuck' => $stuck,
        ];
    }

    /**
     * @param  iterable<AgentExecution>  $executions
     * @return list<array<string, mixed>>
     */
    private function stuckExecutions(iterable $executions, Carbon $now): array
    {
        $results = [];

        foreach ($executions as $execution) {
            $runtimePolicy = is_array($execution->runtime_policy) ? $execution->runtime_policy : [];
            $timeout = max(1, (int) ($runtimePolicy['timeout_seconds'] ?? 120));
            $reference = $execution->started_at ?? $execution->requested_at;

            $age = $reference->diffInSeconds($now);

            $isRunning = in_array($execution->status, [
                AgentExecution::STATUS_REASONING,
                AgentExecution::STATUS_EXECUTING,
            ], true);

            $isRequested = $execution->status === AgentExecution::STATUS_REQUESTED;
            $threshold = $isRequested ? max(300, $timeout * 2) : $timeout + 30;

            if (($isRunning || $isRequested) && $age > $threshold) {
                $results[] = [
                    'execution_id' => $execution->getKey(),
                    'status' => $execution->status,
                    'age_seconds' => $age,
                    'threshold_seconds' => $threshold,
                    'retry_count' => $execution->retry_count,
                    'max_retries' => $execution->max_retries,
                    'failure_code' => $execution->failure_code,
                    'correlation_id' => $execution->correlation_id,
                ];
            }
        }

        return $results;
    }
}