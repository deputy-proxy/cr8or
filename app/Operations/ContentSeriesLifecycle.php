<?php

namespace App\Operations;

use App\Mcp\Tools\TransitionContentSeriesTool;

final class ContentSeriesLifecycle extends DomainTransitionToolOperation
{
    protected static function toolClass(): string
    {
        return TransitionContentSeriesTool::class;
    }
}