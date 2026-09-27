<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateAudienceTool;

final class MarketingAudienceUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateAudienceTool::class;
    }
}