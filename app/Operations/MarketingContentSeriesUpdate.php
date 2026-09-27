<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateContentSeriesTool;

final class MarketingContentSeriesUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateContentSeriesTool::class;
    }
}