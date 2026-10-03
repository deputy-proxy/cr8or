<?php

namespace App\Exceptions;

use RuntimeException;

final class WorkflowDefinitionException extends RuntimeException
{
    /**
     * @param  list<array{stage?: string, code: string, message: string, expert?: string, capability?: string}>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}