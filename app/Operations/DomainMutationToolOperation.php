<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Mcp\Tools\DomainMutationTool;
use App\Models\User;

abstract class DomainMutationToolOperation implements Operation
{
    abstract protected static function toolClass(): string;

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed
    {
        $tool = static::toolClass();
        if (! is_a($tool, DomainMutationTool::class, true)) {
            throw new \LogicException('Invalid domain mutation tool operation.');
        }

        return $tool::executeMutation($actor, $input);
    }
}