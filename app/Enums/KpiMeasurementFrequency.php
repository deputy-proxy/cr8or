<?php

namespace App\Enums;

enum KpiMeasurementFrequency: string
{
    case Daily = 'daily';

    case Weekly = 'weekly';

    case Monthly = 'monthly';

    case Quarterly = 'quarterly';

    case Annually = 'annually';

    case AdHoc = 'ad_hoc';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Daily->value => 'Daily',
            self::Weekly->value => 'Weekly',
            self::Monthly->value => 'Monthly',
            self::Quarterly->value => 'Quarterly',
            self::Annually->value => 'Annually',
            self::AdHoc->value => 'Ad hoc',
        ];
    }
}
