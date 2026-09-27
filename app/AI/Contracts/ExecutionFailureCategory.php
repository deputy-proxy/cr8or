<?php

namespace App\AI\Contracts;

enum ExecutionFailureCategory: string
{
    case Retryable = 'retryable';
    case NonRetryable = 'non_retryable';
    case ApprovalRequired = 'approval_required';
    case AuthorizationFailed = 'authorization_failed';
    case ValidationFailed = 'validation_failed';
    case ExternalServiceFailed = 'external_service_failed';
    case ModelFailed = 'model_failed';
    case ContextMissing = 'context_missing';
    case DelegationFailed = 'delegation_failed';
}