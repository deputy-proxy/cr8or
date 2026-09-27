<?php

namespace App\Operations;

use App\Mcp\Tools\DisconnectSocialAccountTool;

final class SocialAccountDisconnect extends DomainMutationToolOperation
{
    protected static function toolClass(): string
    {
        return DisconnectSocialAccountTool::class;
    }
}