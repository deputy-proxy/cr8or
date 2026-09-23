<?php

namespace App\Exceptions;

use RuntimeException;

final class CanvaClientException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}