<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateEnterpriseContextTool;

final class EnterpriseContextUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateEnterpriseContextTool::class;
    }
}
