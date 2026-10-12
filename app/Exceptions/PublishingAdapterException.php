<?php

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;

class PublishingAdapterException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
        public readonly bool $ambiguous = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException('Publishing adapter failure codes must be canonical identifiers.');
        }

        parent::__construct($message, 0, $previous);
    }
}