<?php

namespace App\Operations;

use App\Mcp\Tools\CreateChannelTool;

final class MarketingChannelCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateChannelTool::class;
    }
}