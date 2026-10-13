<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class SocialAuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}