<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateProjectTool;

final class ProjectUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateProjectTool::class;
    }
}