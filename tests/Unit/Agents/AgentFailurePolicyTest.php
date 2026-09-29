<?php

use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ExecutionErrorType;
use App\AI\Contracts\ExecutionFailureCategory;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\Services\AgentFailurePolicy;

it('classifies every documented Agent failure category and keeps policy deterministic', function () {
    $policy = new AgentFailurePolicy;

    expect($policy->classify(new ExecutionError(ExecutionErrorType::Authorization, 'authorization.denied', 'denied'))['category'])
        ->toBe(ExecutionFailureCategory::AuthorizationFailed)
        ->and($policy->classify(new ExecutionError(ExecutionErrorType::Validation, 'validation.failed', 'invalid'))['category'])
        ->toBe(ExecutionFailureCategory::ValidationFailed)
        ->and($policy->classify(ExecutionError::from(new ModelProviderException(ModelProviderFailureType::Unavailable, 'fake', 'down')))['category'])
        ->toBe(ExecutionFailureCategory::ModelFailed)
        ->and($policy->classify(ExecutionError::from(new ModelProviderException(ModelProviderFailureType::Configuration, 'fake', 'bad config')))['retryable'])
        ->toBeFalse()
        ->and($policy->classify(new ExecutionError(ExecutionErrorType::External, 'delegation.failed', 'delegation failed'))['category'])
        ->toBe(ExecutionFailureCategory::DelegationFailed)
        ->and($policy->classify(new ExecutionError(ExecutionErrorType::BusinessRule, 'business_rule.rejected', 'rejected'))['category'])
        ->toBe(ExecutionFailureCategory::NonRetryable)
        ->and($policy->backoff(1))->toBe(10)
        ->and($policy->backoff(2))->toBe(30)
        ->and($policy->backoff(3))->toBe(60)
        ->and(AgentFailurePolicy::MAX_RETRIES)->toBe(3);
});