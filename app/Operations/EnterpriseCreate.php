<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Mcp\Tools\CreateEnterpriseTool;
use App\Models\User;

final class EnterpriseCreate implements Operation
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed
    {
        return CreateEnterpriseTool::executeOperation($actor, $input);
    }
}