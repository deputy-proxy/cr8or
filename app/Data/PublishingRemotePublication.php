<?php

namespace App\Data;

use InvalidArgumentException;

/** A credential-free reference to an already-created remote publication. */
final readonly class PublishingRemotePublication
{
    public function __construct(
        public string $network,
        public string $destinationExternalId,
        public string $externalPublicationId,
        public string $idempotencyKey,
        public string $correlationId,
        public ?int $integrationConnectionId = null,
        public ?string $credentialReference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $network) !== 1) {
            throw new InvalidArgumentException('Remote publication network must be a canonical lowercase identifier.');
        }

        foreach ([
            'destination external ID' => $destinationExternalId,
            'external publication ID' => $externalPublicationId,
            'idempotency key' => $idempotencyKey,
            'correlation ID' => $correlationId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Remote publication {$field} cannot be empty.");
            }
        }

        if ($credentialReference !== null && trim($credentialReference) === '') {
            throw new InvalidArgumentException('Publishing credential references cannot be empty when supplied.');
        }
    }
}