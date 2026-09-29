<?php

namespace App\AI\Contracts;

use App\AI\Exceptions\ModelProviderException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
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
        public ?string $correlationId = null,
        public ?string $diagnosticId = null,
        public FailureProvenance $provenance = new FailureProvenance,
    ) {}

    public static function from(
        Throwable $exception,
        ?string $correlationId = null,
        ?FailureProvenance $provenance = null,
    ): self {
        $error = match (true) {
            $exception instanceof AuthenticationException => new self(
                ExecutionErrorType::Authentication,
                FailureCode::AUTHENTICATION_REQUIRED,
                'Authentication is required.',
            ),
            $exception instanceof AuthorizationException => new self(
                ExecutionErrorType::Authorization,
                FailureCode::AUTHORIZATION_DENIED,
                'The requested operation is not authorized.',
            ),
            $exception instanceof ValidationException => new self(
                ExecutionErrorType::Validation,
                FailureCode::VALIDATION_FAILED,
                'The request failed validation.',
                details: ['fields' => array_map(
                    static fn (array $messages): array => [reset($messages) ?: 'Invalid value.'],
                    $exception->errors(),
                )],
            ),
            $exception instanceof ModelNotFoundException => new self(
                ExecutionErrorType::Resource,
                FailureCode::RESOURCE_UNAVAILABLE,
                'The requested resource is unavailable.',
            ),
            $exception instanceof ModelProviderException => self::fromProvider($exception),
            $exception instanceof LogicException => new self(
                ExecutionErrorType::BusinessRule,
                FailureCode::BUSINESS_RULE_REJECTED,
                'The operation was rejected by a business rule.',
            ),
            default => new self(
                ExecutionErrorType::Internal,
                FailureCode::INTERNAL_UNEXPECTED,
                'The operation could not be completed.',
            ),
        };

        return new self(
            type: $error->type,
            code: $error->code,
            message: $error->message,
            retryable: $error->retryable,
            details: $error->details,
            correlationId: $correlationId,
            diagnosticId: $error->diagnosticId ?? (string) Str::uuid(),
            provenance: $provenance ?? $error->provenance,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(?string $correlationId = null): array
    {
        $resolvedCorrelationId = $correlationId ?? $this->correlationId;

        return [
            'success' => false,
            'error' => [
                'type' => $this->type->value,
                'code' => $this->code,
                'message' => $this->message,
                'retryable' => $this->retryable,
                'correlation_id' => $resolvedCorrelationId,
                'diagnostic_id' => $this->diagnosticId,
                ...$this->provenance->toArray(),
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