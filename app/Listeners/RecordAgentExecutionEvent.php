<?php

namespace App\Listeners;

use App\Events\AgentExecutionEvent;
use App\Models\AgentExecutionEventRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class RecordAgentExecutionEvent implements ShouldQueue
{
    use Queueable;

    public function handle(AgentExecutionEvent $event): void
    {
        AgentExecutionEventRecord::query()->firstOrCreate(
            ['event_id' => $event->eventId],
            [
                'organization_id' => $event->organizationId,
                'enterprise_id' => $event->enterpriseId,
                'agent_assignment_id' => $event->agentAssignmentId,
                'agent_execution_id' => $event->executionId,
                'actor_id' => $event->actorId,
                'event_type' => $event::class,
                'version' => $event::VERSION,
                'visibility' => $event::VISIBILITY,
                'correlation_id' => $event->correlationId,
                'causation_id' => $event->causationId,
                'provenance' => $event->provenance,
                'data' => $event->data,
                'occurred_at' => $event->occurredAt,
            ],
        );

        Log::info('CR8OR Agent execution event.', $event->toArray());
    }
}