<?php

namespace App\Data\Integrations;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class IntegrationResultEnvelope
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $externalJobId,
        public ?string $externalResultId,
        public string $status,
        public ?string $correlationId,
        public ?string $deliveryId,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
    ) {
        if (! in_array($status, ['pending', 'succeeded', 'failed', 'cancelled', 'expired', 'unknown'], true)) {
            throw new InvalidArgumentException("Unsupported integration result status [{$status}].");
        }
    }

    public function dedupeKey(string $provider): string
    {
        $identity = $this->deliveryId !== null
            ? $this->deliveryId
            : ($this->externalResultId !== null ? $this->externalResultId : $this->externalJobId);

        return hash('sha256', implode('|', [
            $provider,
            $this->externalJobId,
            $identity,
            $this->deliveryId === null ? $this->status : '',
        ]));
    }
}