<?php

namespace App\AI\Exceptions;

enum ModelProviderFailureType: string
{
    case Configuration = 'configuration';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case InvalidResponse = 'invalid_response';
    case Provider = 'provider';
}