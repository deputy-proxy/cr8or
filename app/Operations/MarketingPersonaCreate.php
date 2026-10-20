<?php

namespace App\Operations;

use App\Mcp\Tools\CreatePersonaTool;

final class MarketingPersonaCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreatePersonaTool::class;
    }
}