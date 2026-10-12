<?php

namespace App\Data;

use InvalidArgumentException;

/** @param array<string, int|float|string|bool|null> $metrics */
final readonly class PublishingAnalyticsResult
{
    /** @param array<string, int|float|string|bool|null> $metrics */
    public function __construct(
        public string $externalPublicationId,
        public string $correlationId,
        public string $idempotencyKey,
        public array $metrics,
    ) {
        if (trim($externalPublicationId) === '' || trim($correlationId) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Publishing analytics results must preserve remote identity, correlation and idempotency metadata.');
        }
    }
}