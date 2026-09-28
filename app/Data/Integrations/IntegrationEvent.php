<?php

namespace App\Data\Integrations;

use App\Contracts\PlatformEvent;
use App\Services\PlatformEventSanitizer;
use DateTimeInterface;
use Illuminate\Support\Str;

final readonly class IntegrationEvent implements PlatformEvent
{
    public const VERSION = 1;

    public const CATEGORY = 'integration';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public string $eventId;

    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventType,
        public int $organizationId,
        public int $enterpriseId,
        public string $integration,
        public string $provider,
        public string $direction,
        public ?string $correlationId,
        public ?string $causationId,
        public string $schemaVersion,
        public DateTimeInterface $occurredAt,
        array $payload,
        string $eventId = '',
    ) {
        if (! in_array($direction, [self::DIRECTION_OUTBOUND, self::DIRECTION_INBOUND], true)) {
            throw new \InvalidArgumentException("Unsupported integration event direction [{$direction}].");
        }

        $this->eventId = $eventId !== '' ? $eventId : (string) Str::uuid();
        $this->payload = PlatformEventSanitizer::sanitize($payload);
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function category(): string
    {
        return self::CATEGORY;
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
            'integration' => $this->integration,
            'provider' => $this->provider,
            'direction' => $this->direction,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}