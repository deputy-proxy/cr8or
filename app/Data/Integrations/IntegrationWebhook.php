<?php

namespace App\Data\Integrations;

final readonly class IntegrationWebhook
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $integration,
        public string $provider,
        public string $eventType,
        public string $externalId,
        public ?string $correlationId,
        public string $deliveryId,
        public array $payload,
    ) {}
}