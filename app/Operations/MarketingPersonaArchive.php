<?php

namespace App\Operations;

use App\Mcp\Tools\ArchivePersonaTool;

final class MarketingPersonaArchive extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return ArchivePersonaTool::class;
    }
}