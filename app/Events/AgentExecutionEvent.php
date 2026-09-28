<?php

namespace App\Events;

use App\Contracts\PlatformEvent;
use App\Services\PlatformEventSanitizer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract readonly class AgentExecutionEvent implements PlatformEvent, ShouldDispatchAfterCommit
{
    public const VERSION = 1;

    public const VISIBILITY = 'internal';

    /** @var array<string, mixed> */
    public array $provenance;

    /** @var array<string, mixed> */
    public array $data;

    public string $eventId;

    public Carbon $occurredAt;

    /**
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $executionId,
        public ?int $enterpriseId,
        public ?int $agentAssignmentId,
        public ?string $agentSlug,
        public ?int $actorId,
        public ?string $correlationId,
        public ?string $causationId = null,
        array $provenance = [],
        array $data = [],
        ?string $eventId = null,
        ?Carbon $occurredAt = null,
        public ?int $organizationId = null,
    ) {
        $this->provenance = PlatformEventSanitizer::sanitize($provenance);
        $this->data = PlatformEventSanitizer::sanitize($data);
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? Carbon::now();
    }

    public function category(): string
    {
        return 'agent_execution';
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'execution_id' => $this->executionId,
            'organization_id' => $this->organizationId,
            'enterprise_id' => $this->enterpriseId,
            'agent_assignment_id' => $this->agentAssignmentId,
            'agent_slug' => $this->agentSlug,
            'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'provenance' => $this->provenance,
            'data' => $this->data,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => static::class,
            'category' => $this->category(),
            'event_id' => $this->eventId,
            'version' => self::VERSION,
            'visibility' => self::VISIBILITY,
            'execution_id' => $this->executionId,
            'organization_id' => $this->organizationId,
            'enterprise_id' => $this->enterpriseId,
            'agent_assignment_id' => $this->agentAssignmentId,
            'agent_slug' => $this->agentSlug,
            'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'provenance' => $this->provenance,
            'data' => $this->data,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }
}