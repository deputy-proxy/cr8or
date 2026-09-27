<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Mcp\Tools\DomainTransitionTool;
use App\Models\User;

abstract class DomainTransitionToolOperation implements Operation
{
    abstract protected static function toolClass(): string;

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed
    {
        $tool = static::toolClass();
        if (! is_a($tool, DomainTransitionTool::class, true)) {
            throw new \LogicException('Invalid domain transition tool operation.');
        }

        return $tool::executeTransition($actor, $input);
    }
}