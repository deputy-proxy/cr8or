<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateCampaignTool;

final class MarketingCampaignUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateCampaignTool::class;
    }
}