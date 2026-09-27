<?php

namespace App\Operations;

use App\Mcp\Tools\CreateAudienceTool;

final class MarketingAudienceCreate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return CreateAudienceTool::class;
    }
}