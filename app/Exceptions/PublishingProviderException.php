<?php

namespace App\Exceptions;

class PublishingProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $failureCode, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}