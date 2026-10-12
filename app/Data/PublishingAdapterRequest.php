<?php

namespace App\Data;

/**
 * @param  list<array<string, mixed>>  $media
 * @param  array<string, mixed>  $settings
 */
final readonly class PublishingAdapterRequest
{
    /**
     * @param  list<array<string, mixed>>  $media
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $network,
        public string $destinationExternalId,
        public string $content,
        public array $media,
        public ?string $scheduledAt,
        public string $idempotencyKey,
        public string $correlationId,
        public ?int $integrationConnectionId = null,
        public ?string $credentialReference = null,
        public array $settings = [],
    ) {}
}