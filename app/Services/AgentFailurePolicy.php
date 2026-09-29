<?php

namespace App\Services;

use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ExecutionFailureCategory;

final class AgentFailurePolicy
{
    public const MAX_RETRIES = 3;

    /** @return array{category: ExecutionFailureCategory, retryable: bool, terminal: bool} */
    public function classify(ExecutionError $error): array
    {
        $category = match (true) {
            str_starts_with($error->code, 'delegation.') => ExecutionFailureCategory::DelegationFailed,
            str_starts_with($error->code, 'approval.') => ExecutionFailureCategory::ApprovalRequired,
            $error->type->value === 'authentication', $error->type->value === 'authorization' => ExecutionFailureCategory::AuthorizationFailed,
            $error->type->value === 'validation' => ExecutionFailureCategory::ValidationFailed,
            $error->type->value === 'provider' => ExecutionFailureCategory::ModelFailed,
            $error->type->value === 'resource' => ExecutionFailureCategory::ContextMissing,
            $error->type->value === 'external' => ExecutionFailureCategory::ExternalServiceFailed,
            $error->type->value === 'business_rule' => ExecutionFailureCategory::NonRetryable,
            default => $error->retryable ? ExecutionFailureCategory::Retryable : ExecutionFailureCategory::NonRetryable,
        };

        return [
            'category' => $category,
            'retryable' => $error->retryable && in_array($category, [
                ExecutionFailureCategory::Retryable,
                ExecutionFailureCategory::ModelFailed,
                ExecutionFailureCategory::ExternalServiceFailed,
            ], true),
            'terminal' => ! $error->retryable,
        ];
    }

    public function backoff(int $attempt): int
    {
        return match (max(1, $attempt)) {
            1 => 10,
            2 => 30,
            default => 60,
        };
    }
}