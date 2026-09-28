<?php

namespace App\Events;

use App\Contracts\PlatformEvent;
use App\Services\PlatformEventSanitizer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract readonly class DomainEvent implements PlatformEvent, ShouldDispatchAfterCommit
{
    public const VERSION = 1;

    public const CATEGORY = 'domain';

    public string $eventId;

    public Carbon $occurredAt;

    /** @var array<string, mixed> */
    public array $data;

    /** @param array<string, mixed> $data */
    public function __construct(
        public int $organizationId,
        public ?int $enterpriseId,
        public ?int $actorId,
        public ?string $correlationId,
        public ?string $causationId = null,
        array $data = [],
        ?string $eventId = null,
        ?Carbon $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? Carbon::now();
        $this->data = PlatformEventSanitizer::sanitize($data);
    }

    public function category(): string
    {
        return self::CATEGORY;
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
            'organization_id' => $this->organizationId,
            'enterprise_id' => $this->enterpriseId,
            'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->data;
    }
}