<?php

namespace App\Data\Integrations;

use App\Contracts\PlatformEvent;
use App\Services\PlatformEventSanitizer;

final readonly class IntegrationWebhook implements PlatformEvent
{
    public const VERSION = 1;

    public const CATEGORY = 'external_webhook';

    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $integration,
        public string $provider,
        public string $eventType,
        public string $externalId,
        public ?string $correlationId,
        public ?string $causationId,
        public string $deliveryId,
        array $payload,
    ) {
        $this->payload = PlatformEventSanitizer::sanitize($payload);
    }

    public function category(): string
    {
        return self::CATEGORY;
    }

    public function eventId(): string
    {
        return $this->deliveryId;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'integration' => $this->integration,
            'provider' => $this->provider,
            'event_type' => $this->eventType,
            'external_id' => $this->externalId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'delivery_id' => $this->deliveryId,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}