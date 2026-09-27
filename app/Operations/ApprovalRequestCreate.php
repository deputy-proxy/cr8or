<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Mcp\Tools\RequestApprovalTool;
use App\Models\User;

final class ApprovalRequestCreate implements Operation
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): mixed
    {
        return RequestApprovalTool::executeOperation($actor, $input);
    }
}