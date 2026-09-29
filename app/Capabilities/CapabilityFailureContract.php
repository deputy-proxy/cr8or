<?php

namespace App\Capabilities;

use App\AI\Contracts\FailureCode;
use InvalidArgumentException;

final readonly class CapabilityFailureContract
{
    /** @param array<string, list<string>> $codes */
    public function __construct(public array $codes)
    {
        if ($codes === []) {
            throw new InvalidArgumentException('A Capability failure contract must define at least one failure category.');
        }

        foreach ($codes as $category => $categoryCodes) {
            if ($category === '' || $categoryCodes === []) {
                throw new InvalidArgumentException('Capability failure contract categories must define canonical failure codes.');
            }
        }
    }

    public static function standard(): self
    {
        return new self([
            'validation' => [FailureCode::VALIDATION_FAILED],
            'authorization' => [FailureCode::AUTHENTICATION_REQUIRED, FailureCode::AUTHORIZATION_DENIED, FailureCode::CAPABILITY_DENIED],
            'approval' => [FailureCode::APPROVAL_REQUIRED, FailureCode::APPROVAL_DENIED, FailureCode::APPROVAL_EXPIRED],
            'resource' => [FailureCode::RESOURCE_NOT_FOUND, FailureCode::RESOURCE_UNAVAILABLE],
            'conflict' => [FailureCode::CONFLICT_DETECTED],
            'lifecycle' => [FailureCode::LIFECYCLE_INVALID_TRANSITION, FailureCode::LIFECYCLE_TIMEOUT, FailureCode::LIFECYCLE_CANCELLED],
            'business' => [FailureCode::BUSINESS_RULE_REJECTED],
            'persistence' => [FailureCode::PERSISTENCE_FAILED],
            'external' => [FailureCode::EXTERNAL_AUTHENTICATION, FailureCode::EXTERNAL_AUTHORIZATION, FailureCode::EXTERNAL_TIMEOUT, FailureCode::EXTERNAL_RATE_LIMITED, FailureCode::EXTERNAL_UNAVAILABLE, FailureCode::EXTERNAL_INVALID_RESPONSE, FailureCode::EXTERNAL_REJECTED, FailureCode::EXTERNAL_PARTIAL],
            'provider' => [FailureCode::PROVIDER_CONFIGURATION, FailureCode::PROVIDER_TIMEOUT, FailureCode::PROVIDER_RATE_LIMITED, FailureCode::PROVIDER_UNAVAILABLE, FailureCode::PROVIDER_INVALID_RESPONSE, FailureCode::PROVIDER_REJECTED],
            'queue' => [FailureCode::QUEUE_FAILED, FailureCode::QUEUE_TIMEOUT],
            'configuration' => [FailureCode::CONFIGURATION_INVALID],
            'serialization' => [FailureCode::SERIALIZATION_FAILED],
            'internal' => [FailureCode::INTERNAL_UNEXPECTED],
        ]);
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->codes;
    }
}