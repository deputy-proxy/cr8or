<?php

namespace App\Data\Integrations;

final readonly class IntegrationExecutionContext
{
    /** @param array<string, mixed> $resource */
    public function __construct(
        public int $organizationId,
        public int $enterpriseId,
        public string $integration,
        public string $provider,
        public array $resource,
        public string $correlationId,
        public string $idempotencyKey,
    ) {}
}