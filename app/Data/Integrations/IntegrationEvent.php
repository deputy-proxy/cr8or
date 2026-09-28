<?php

namespace App\Data\Integrations;

use DateTimeInterface;

final readonly class IntegrationEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventType,
        public int $organizationId,
        public int $enterpriseId,
        public string $integration,
        public string $provider,
        public ?string $correlationId,
        public ?string $causationId,
        public string $schemaVersion,
        public DateTimeInterface $occurredAt,
        public array $payload,
    ) {}
}