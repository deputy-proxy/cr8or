<?php

namespace App\Enums;

enum ProductType: string
{
    case SERVICE = 'service';
    case DIGITAL_PRODUCT = 'digital_product';
    case SUBSCRIPTION = 'subscription';
    case COURSE = 'course';
    case EDITORIAL = 'editorial';
    case PHYSICAL_PRODUCT = 'physical_product';
    case OTHER = 'other';
}
