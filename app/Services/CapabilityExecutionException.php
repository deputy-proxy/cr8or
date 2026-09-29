<?php

namespace App\Services;

use App\AI\Contracts\ExecutionError;
use RuntimeException;

final class CapabilityExecutionException extends RuntimeException
{
    public function __construct(public readonly ExecutionError $failure, \Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}