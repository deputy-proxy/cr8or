<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\User;

final class ExecuteAgent implements Operation
{
    public function __construct(private readonly CreateAgentExecution $create) {}

    public function execute(User $actor, array $input): mixed
    {
        return $this->create->execute($actor, $input);
    }
}
