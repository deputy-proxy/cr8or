<?php

namespace App\AI\Exceptions;

use RuntimeException;
use Throwable;

final class ModelProviderException extends RuntimeException
{
    public function __construct(
        public readonly ModelProviderFailureType $type,
        public readonly string $provider,
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}