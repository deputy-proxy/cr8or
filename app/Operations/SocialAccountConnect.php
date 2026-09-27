<?php

namespace App\Operations;

use App\Mcp\Tools\ConnectSocialAccountTool;

final class SocialAccountConnect extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return ConnectSocialAccountTool::class;
    }
}