<?php

namespace App\AI\Contracts;

enum ExecutionErrorType: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Validation = 'validation';
    case UnavailableResource = 'unavailable_resource';
    case BusinessRule = 'business_rule';
    case Provider = 'provider';
    case ExternalExecution = 'external_execution';
    case Internal = 'internal';
}
