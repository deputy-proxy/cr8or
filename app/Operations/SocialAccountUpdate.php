<?php

namespace App\Operations;

use App\Mcp\Tools\UpdateSocialAccountTool;

final class SocialAccountUpdate extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return UpdateSocialAccountTool::class;
    }
}