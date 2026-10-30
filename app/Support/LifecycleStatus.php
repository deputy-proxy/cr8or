<?php

namespace App\Support;

final class LifecycleStatus
{
    /** @var list<string> */
    private const CLOSED_STATES = [
        'closed',
        'complete',
        'completed',
        'done',
        'cancelled',
        'canceled',
        'archived',
        'deleted',
        'rejected',
    ];

    public static function isClosed(mixed $status): bool
    {
        return is_string($status)
            && in_array(strtolower(trim($status)), self::CLOSED_STATES, true);
    }
}
