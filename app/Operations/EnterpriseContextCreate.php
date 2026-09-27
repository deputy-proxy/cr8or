<?php

namespace App\Operations;

use App\Mcp\Tools\CreateEnterpriseContextTool;

final class EnterpriseContextCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateEnterpriseContextTool::class;
    }
}