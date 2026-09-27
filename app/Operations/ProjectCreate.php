<?php

namespace App\Operations;

use App\Mcp\Tools\CreateProjectTool;

final class ProjectCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateProjectTool::class;
    }
}