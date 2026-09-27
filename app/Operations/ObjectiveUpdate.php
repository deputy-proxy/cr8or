<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateObjectiveTool;

final class ObjectiveUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateObjectiveTool::class;
    }
}