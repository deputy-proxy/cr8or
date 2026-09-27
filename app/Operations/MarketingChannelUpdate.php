<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateChannelTool;

final class MarketingChannelUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateChannelTool::class;
    }
}