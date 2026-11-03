<?php

namespace App\Support;

use BackedEnum;

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

    /** @return list<string> */
    public static function closedStatuses(): array
    {
        return self::CLOSED_STATES;
    }

    public static function isClosed(mixed $status): bool
    {
        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        return is_string($status)
            && in_array(strtolower(trim($status)), self::CLOSED_STATES, true);
    }
}
