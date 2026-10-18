<?php

namespace App\Enums;

enum ProductDeliveryModel: string
{
    case DIGITAL_SELF_SERVICE = 'digital_self_service';
    case EDITORIAL_CONTENT_ACCESS = 'editorial_content_access';
    case DELIVERED_SERVICE = 'delivered_service';
    case SUBSCRIPTION_RECURRING_ACCESS = 'subscription_recurring_access';
    case PHYSICAL_FULFILLMENT = 'physical_fulfillment';
    case HYBRID = 'hybrid';
    case OTHER = 'other';
}
