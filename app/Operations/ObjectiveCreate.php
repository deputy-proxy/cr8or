<?php

namespace App\Operations;

use App\Mcp\Tools\CreateObjectiveTool;

final class ObjectiveCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateObjectiveTool::class;
    }
}