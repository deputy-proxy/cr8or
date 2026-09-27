<?php

namespace App\Operations;

use App\Mcp\Tools\CreateContentSeriesTool;

final class MarketingContentSeriesCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateContentSeriesTool::class;
    }
}