<?php

namespace App\Operations;

use App\Mcp\Tools\CreateConnectionTool;

final class IntegrationConnectionCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateConnectionTool::class;
    }
}
