<?php

namespace App\Operations;

use App\Mcp\Tools\ArchiveAudienceTool;

final class MarketingAudienceArchive extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return ArchiveAudienceTool::class;
    }
}