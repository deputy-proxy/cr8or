<?php

namespace App\Operations;

use App\Mcp\Tools\CreateCampaignTool;

final class MarketingCampaignCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateCampaignTool::class;
    }
}