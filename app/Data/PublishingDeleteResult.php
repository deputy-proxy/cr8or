<?php

namespace App\Data;

use InvalidArgumentException;

/** @param array<string, mixed> $payload */
final readonly class PublishingDeleteResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $externalPublicationId,
        public bool $deleted,
        public string $correlationId,
        public string $idempotencyKey,
        public array $payload = [],
    ) {
        if (trim($externalPublicationId) === '' || trim($correlationId) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Publishing delete results must preserve remote identity, correlation and idempotency metadata.');
        }
    }
}