<?php

namespace App\Operations;

use App\Mcp\Tools\CreateProductTool;

final class ProductCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateProductTool::class;
    }
}
