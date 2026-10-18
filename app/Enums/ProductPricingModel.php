<?php

namespace App\Enums;

enum ProductPricingModel: string
{
    case FREE = 'free';
    case FIXED = 'fixed';
    case USAGE_BASED = 'usage_based';
    case SUBSCRIPTION = 'subscription';
    case QUOTE_BASED = 'quote_based';
    case OTHER = 'other';
}
