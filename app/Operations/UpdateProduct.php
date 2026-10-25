<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateProductTool;

final class UpdateProduct extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateProductTool::class;
    }
}
