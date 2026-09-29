<?php

namespace App\Exceptions;

use RuntimeException;

final class MediaStorageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureCode = 'storage.unavailable',
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}