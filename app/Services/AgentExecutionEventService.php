<?php

namespace App\Services;

use App\Events\AgentExecutionEvent;
use App\Models\AgentExecution;
use InvalidArgumentException;

final class AgentExecutionEventService
{
    /**
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $eventClass, AgentExecution $execution, array $provenance = [], array $data = [], ?string $causationId = null): void
    {
        if (! is_a($eventClass, AgentExecutionEvent::class, true)) {
            throw new InvalidArgumentException("[{$eventClass}] is not an Agent execution event.");
        }

        event(new $eventClass(
            executionId: (int) $execution->getKey(),
            enterpriseId: $execution->enterprise_id === null ? null : (int) $execution->enterprise_id,
            agentAssignmentId: $execution->agent_assignment_id === null ? null : (int) $execution->agent_assignment_id,
            agentSlug: $execution->agent_slug,
            actorId: $execution->actor_id === null ? null : (int) $execution->actor_id,
            correlationId: $execution->correlation_id,
            causationId: $causationId,
            provenance: $provenance,
            data: array_merge(['mode' => $execution->mode->value], $data),
            organizationId: (int) $execution->organization_id,
        ));
    }
}