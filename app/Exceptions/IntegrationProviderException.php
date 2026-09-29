<?php

namespace App\Exceptions;

use RuntimeException;

final class IntegrationProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $retryable = false,
        public readonly ?string $externalId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}