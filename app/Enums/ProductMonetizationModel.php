<?php

namespace App\Enums;

enum ProductMonetizationModel: string
{
    case FREE = 'free';
    case ONE_TIME_PURCHASE = 'one_time_purchase';
    case SUBSCRIPTION = 'subscription';
    case SERVICE_FEE = 'service_fee';
    case AFFILIATE_SUPPORTED = 'affiliate_supported';
    case HYBRID = 'hybrid';
    case QUOTE_BASED = 'quote_based';
    case OTHER = 'other';
}
