<?php

namespace App\Operations;

use App\Mcp\Tools\ArchiveMarketingStrategyTool;

final class MarketingStrategyArchive extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return ArchiveMarketingStrategyTool::class;
    }
}