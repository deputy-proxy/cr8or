<?php

namespace App\Listeners;

use App\Contracts\PlatformEvent;
use App\Events\AgentExecutionEvent;
use App\Events\DomainEvent;
use App\Models\Enterprise;
use App\Services\EnterpriseEventRecorder;
use Illuminate\Support\Str;

final class RecordEnterpriseActivityEvent
{
    public function __construct(private readonly EnterpriseEventRecorder $recorder) {}

    public function handleDomain(DomainEvent $event): void
    {
        $this->record(
            $event,
            $event->eventId,
            $event->organizationId,
            $event->enterpriseId,
            $event->actorId,
            $event->correlationId,
            $event->causationId,
            $event->occurredAt,
            $event->payload(),
            $event->metadata(),
        );
    }

    public function handleAgent(AgentExecutionEvent $event): void
    {
        if ($event->enterpriseId === null || $event->organizationId === null) {
            return;
        }

        $this->record(
            $event,
            $event->eventId,
            $event->organizationId,
            $event->enterpriseId,
            $event->actorId,
            $event->correlationId,
            $event->causationId,
            $event->occurredAt,
            ['agent_slug' => $event->agentSlug, 'event' => class_basename($event)],
            $event->metadata(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        PlatformEvent $event,
        string $eventId,
        int $organizationId,
        ?int $enterpriseId,
        ?int $actorId,
        ?string $correlationId,
        ?string $causationId,
        \Illuminate\Support\Carbon $occurredAt,
        array $payload,
        array $metadata,
    ): void {
        if ($enterpriseId === null) {
            return;
        }

        $enterprise = Enterprise::query()->find($enterpriseId);
        if ($enterprise === null || (int) $enterprise->organization_id !== $organizationId) {
            return;
        }

        $eventName = class_basename($event);
        $display = Str::headline($eventName);
        $recordName = $payload['name'] ?? $payload['title'] ?? null;
        if (is_string($recordName) && trim($recordName) !== '') {
            $display .= ': '.trim($recordName);
        }

        $this->recorder->record(
            organizationId: $organizationId,
            enterpriseId: $enterpriseId,
            source: 'cr8or',
            eventType: Str::snake($eventName),
            description: $display,
            occurredAt: $occurredAt,
            payload: $payload,
            sourceEventId: $eventId,
            actorId: $actorId,
            subjectType: isset($payload['entity_type']) && is_string($payload['entity_type']) ? $payload['entity_type'] : null,
            subjectId: isset($payload['entity_id']) && is_numeric($payload['entity_id']) ? (string) $payload['entity_id'] : (isset($metadata['execution_id']) ? (string) $metadata['execution_id'] : null),
            correlationId: $correlationId,
            causationId: $causationId,
        );
    }
}
