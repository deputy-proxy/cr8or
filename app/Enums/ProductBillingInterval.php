<?php

namespace App\Enums;

enum ProductBillingInterval: string
{
    case ONE_TIME = 'one_time';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';

    /** @return list<string> */
    public static function recurringValues(): array
    {
        return [self::WEEKLY->value, self::MONTHLY->value, self::QUARTERLY->value, self::YEARLY->value];
    }
}
