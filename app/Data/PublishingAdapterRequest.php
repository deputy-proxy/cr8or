<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Normalized publication input. Credential material is represented only by an opaque reference.
 *
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
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $network) !== 1) {
            throw new InvalidArgumentException('Publishing request network must be a canonical lowercase identifier.');
        }

        foreach ([
            'destination external ID' => $destinationExternalId,
            'idempotency key' => $idempotencyKey,
            'correlation ID' => $correlationId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Publishing request {$field} cannot be empty.");
            }
        }

        self::assertMediaList($media);

        if ($credentialReference !== null && trim($credentialReference) === '') {
            throw new InvalidArgumentException('Publishing credential references cannot be empty when supplied.');
        }
    }

    /** @param array<array-key, mixed> $media */
    private static function assertMediaList(array $media): void
    {
        if (! array_is_list($media)) {
            throw new InvalidArgumentException('Publishing request media must be a list.');
        }

        foreach ($media as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Publishing request media entries must be structured metadata arrays.');
            }
        }
    }
}