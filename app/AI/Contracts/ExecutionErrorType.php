<?php

namespace App\AI\Contracts;

enum ExecutionErrorType: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Validation = 'validation';
    case Resource = 'resource';
    case Conflict = 'conflict';
    case Lifecycle = 'lifecycle';
    case BusinessRule = 'business_rule';
    case Capability = 'capability';
    case Approval = 'approval';
    case Provider = 'provider';
    case External = 'external';
    case Persistence = 'persistence';
    case Queue = 'queue';
    case Configuration = 'configuration';
    case Serialization = 'serialization';
    case Internal = 'internal';
}