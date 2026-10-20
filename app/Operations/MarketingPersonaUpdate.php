<?php

namespace App\Operations;

use App\Mcp\Tools\UpdatePersonaTool;

final class MarketingPersonaUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdatePersonaTool::class;
    }
}