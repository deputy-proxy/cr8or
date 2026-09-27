<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\AgentExecutionEventRecord;
use Illuminate\Support\Collection;

final class AgentExecutionTimelineService
{
    /** @return Collection<int, AgentExecutionEventRecord> */
    public function forExecution(AgentExecution $execution): Collection
    {
        return AgentExecutionEventRecord::query()
            ->where('organization_id', $execution->organization_id)
            ->where('agent_execution_id', $execution->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, AgentExecutionEventRecord> */
    public function graph(AgentExecution $execution): Collection
    {
        $ids = collect([$execution->getKey()]);
        $delegations = $execution->delegationsFrom()->with('targetAgentExecution')->get();
        foreach ($delegations as $delegation) {
            if ($delegation->target_agent_execution_id !== null) {
                $ids->push($delegation->target_agent_execution_id);
            }
        }

        return AgentExecutionEventRecord::query()
            ->where('organization_id', $execution->organization_id)
            ->whereIn('agent_execution_id', $ids->unique()->all())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }
}