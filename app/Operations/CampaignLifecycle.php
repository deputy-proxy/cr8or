<?php

namespace App\Operations;

use App\Mcp\Tools\TransitionCampaignTool;

final class CampaignLifecycle extends DomainTransitionToolOperation
{
    protected static function toolClass(): string
    {
        return TransitionCampaignTool::class;
    }
}