<?php

namespace App\Exceptions;

use RuntimeException;

final class SocialAuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}