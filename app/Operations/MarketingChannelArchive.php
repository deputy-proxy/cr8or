<?php

namespace App\Operations;

use App\Mcp\Tools\ArchiveChannelTool;

final class MarketingChannelArchive extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return ArchiveChannelTool::class;
    }
}