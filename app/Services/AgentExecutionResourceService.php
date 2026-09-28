<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

final class AgentExecutionResourceService
{
    /** @return array<string, mixed> */
    public function get(User $actor, Enterprise $enterprise, AgentExecution $execution): array
    {
        if ((int) $execution->enterprise_id !== (int) $enterprise->getKey() || (int) $execution->organization_id !== (int) $enterprise->organization_id) {
            throw new \InvalidArgumentException('Agent execution does not belong to the requested Enterprise.');
        }

        Gate::forUser($actor)->authorize('view', $execution);

        $inspection = app(AgentExecutionOperationsService::class)->inspect($actor, $execution);
        $inspection['execution'] = [
            ...$inspection['execution'],
            'idempotency_key' => $execution->idempotency_key,
            'prompt' => $execution->prompt,
            'target_context' => $execution->target_context ?? [],
            'execution_context' => $execution->execution_context ?? [],
            'result' => $execution->last_result ?? null,
        ];

        return $inspection;
    }

    /** @return Collection<int, AgentExecution> */
    public function list(User $actor, Enterprise $enterprise, ?int $assignmentId = null, ?string $status = null, int $limit = 50): Collection
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return AgentExecution::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->when($assignmentId !== null, fn ($query) => $query->where('agent_assignment_id', $assignmentId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(min(50, max(1, $limit)))
            ->get();
    }
}
