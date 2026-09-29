<?php

namespace App\AI\Contracts;

/**
 * Canonical client-visible CR8OR failure codes.
 *
 * Codes describe the failure, never the PHP exception class or implementation
 * that happened to produce it.
 */
final class FailureCode
{
    public const AUTHENTICATION_REQUIRED = 'authentication.required';

    public const AUTHORIZATION_DENIED = 'authorization.denied';

    public const VALIDATION_FAILED = 'validation.failed';

    public const RESOURCE_UNAVAILABLE = 'resource.unavailable';

    public const RESOURCE_NOT_FOUND = 'resource.not_found';

    public const CONFLICT_DETECTED = 'conflict.detected';

    public const LIFECYCLE_INVALID_TRANSITION = 'lifecycle.invalid_transition';

    public const BUSINESS_RULE_REJECTED = 'business_rule.rejected';

    public const CAPABILITY_DENIED = 'capability.denied';

    public const APPROVAL_REQUIRED = 'approval.required';

    public const APPROVAL_DENIED = 'approval.denied';

    public const APPROVAL_EXPIRED = 'approval.expired';

    public const PROVIDER_CONFIGURATION = 'provider.configuration';

    public const PROVIDER_TIMEOUT = 'provider.timeout';

    public const PROVIDER_RATE_LIMITED = 'provider.rate_limited';

    public const PROVIDER_UNAVAILABLE = 'provider.unavailable';

    public const PROVIDER_INVALID_RESPONSE = 'provider.invalid_response';

    public const PROVIDER_REJECTED = 'provider.rejected';

    public const EXTERNAL_AUTHENTICATION = 'external.authentication';

    public const EXTERNAL_AUTHORIZATION = 'external.authorization';

    public const EXTERNAL_TIMEOUT = 'external.timeout';

    public const EXTERNAL_RATE_LIMITED = 'external.rate_limited';

    public const EXTERNAL_UNAVAILABLE = 'external.unavailable';

    public const EXTERNAL_INVALID_RESPONSE = 'external.invalid_response';

    public const EXTERNAL_REJECTED = 'external.rejected';

    public const EXTERNAL_PARTIAL = 'external.partial';

    public const PERSISTENCE_FAILED = 'persistence.failed';

    public const QUEUE_FAILED = 'queue.failed';

    public const QUEUE_TIMEOUT = 'queue.timeout';

    public const CONFIGURATION_INVALID = 'configuration.invalid';

    public const SERIALIZATION_FAILED = 'serialization.failed';

    public const INTERNAL_UNEXPECTED = 'internal.unexpected';

    private function __construct() {}
}