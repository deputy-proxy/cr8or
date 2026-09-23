<?php

namespace App\AI\Contracts;

use App\AI\Exceptions\ModelProviderException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final readonly class ExecutionError
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public ExecutionErrorType $type,
        public string $code,
        public string $message,
        public bool $retryable = false,
        public array $details = [],
    ) {}

    public static function from(Throwable $exception): self
    {
        return match (true) {
            $exception instanceof AuthenticationException => new self(ExecutionErrorType::Authentication, 'authentication.required', 'Authentication is required.'),
            $exception instanceof AuthorizationException => new self(ExecutionErrorType::Authorization, 'authorization.denied', 'The requested operation is not authorized.'),
            $exception instanceof ValidationException => new self(ExecutionErrorType::Validation, 'validation.failed', 'The request failed validation.', details: array_map(static fn (array $messages): array => [reset($messages) ?: 'Invalid value.'], $exception->errors())),
            $exception instanceof ModelNotFoundException => new self(ExecutionErrorType::UnavailableResource, 'resource.unavailable', 'The requested resource is unavailable.'),
            $exception instanceof ModelProviderException => self::fromProvider($exception),
            $exception instanceof LogicException => new self(ExecutionErrorType::BusinessRule, 'business_rule.rejected', 'The operation was rejected by a business rule.'),
            default => new self(ExecutionErrorType::Internal, 'internal.error', 'The operation could not be completed.'),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(string $correlationId): array
    {
        return [
            'success' => false,
            'error' => [
                'type' => $this->type->value,
                'code' => $this->code,
                'message' => $this->message,
                'retryable' => $this->retryable,
                'correlation_id' => $correlationId,
                'details' => $this->details,
            ],
        ];
    }

    private static function fromProvider(ModelProviderException $exception): self
    {
        return new self(
            ExecutionErrorType::Provider,
            'provider.'.$exception->type->value,
            'The model provider could not complete the execution.',
            retryable: in_array($exception->type->value, ['timeout', 'rate_limited', 'unavailable'], true),
        );
    }
}
