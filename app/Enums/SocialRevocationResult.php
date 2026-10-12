<?php

namespace App\Enums;

enum SocialRevocationResult: string
{
    case Revoked = 'revoked';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
}