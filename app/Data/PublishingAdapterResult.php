<?php

namespace App\Data;

use InvalidArgumentException;

/** @param array<string, mixed> $payload */
final readonly class PublishingAdapterResult
{
    private const STATUSES = ['submitted', 'accepted', 'pending', 'published', 'succeeded', 'failed'];

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $externalId,
        public ?string $externalUrl,
        public string $status,
        public string $correlationId,
        public string $idempotencyKey,
        public array $payload = [],
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Publishing adapter results must use a normalized status.');
        }

        if ($status !== 'failed' && trim($externalId) === '') {
            throw new InvalidArgumentException('Successful or pending publishing results require an external identifier.');
        }

        if (trim($correlationId) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Publishing adapter results must preserve correlation and idempotency metadata.');
        }

        if ($externalUrl !== null && (filter_var($externalUrl, FILTER_VALIDATE_URL) === false || parse_url($externalUrl, PHP_URL_SCHEME) !== 'https' || ! is_string(parse_url($externalUrl, PHP_URL_HOST)))) {
            throw new InvalidArgumentException('Publishing adapter result URLs must use HTTPS.');
        }
    }
}