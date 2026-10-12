<?php

namespace App\Enums;

enum PublishingCapabilitySupport: string
{
    case Supported = 'supported';
    case Conditional = 'conditional';
    case Unsupported = 'unsupported';
}