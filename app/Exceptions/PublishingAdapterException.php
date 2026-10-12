<?php

namespace App\Exceptions;

class PublishingAdapterException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
        public readonly bool $ambiguous = false,
    ) {
        parent::__construct($message, 0, $previous);
    }
}