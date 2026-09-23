<?php

namespace App\Data;

/** @param array<string, mixed> $settings */
final readonly class PublishingRequest
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $integrationId,
        public string $content,
        public string $scheduledAt,
        public string $idempotencyKey,
        public array $settings = [],
    ) {}
}
