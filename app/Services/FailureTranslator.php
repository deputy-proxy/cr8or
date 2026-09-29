<?php

namespace App\Services;

use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ExecutionErrorType;
use App\AI\Contracts\FailureCode;
use App\AI\Contracts\FailureProvenance;
use App\AI\Exceptions\ModelProviderException;
use App\Exceptions\CanvaClientException;
use App\Exceptions\PublishingProviderException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

final class FailureTranslator
{
    /** @var WeakMap<Throwable, string> */
    private WeakMap $diagnosticIds;

    public function __construct()
    {
        $this->diagnosticIds = new WeakMap;
    }

    public function translate(
        Throwable $exception,
        ?string $correlationId = null,
        ?FailureProvenance $provenance = null,
    ): ExecutionError {
        if ($exception instanceof CapabilityExecutionException) {
            return $this->failureWithContext($exception->failure, $correlationId, $provenance);
        }

        $diagnosticId = $this->diagnosticIds[$exception] ?? null;

        if ($diagnosticId === null) {
            $diagnosticId = (string) Str::uuid();
            $this->diagnosticIds[$exception] = $diagnosticId;
            $this->recordDiagnostic($exception, $diagnosticId, $correlationId, $provenance);
        }

        $error = $this->map($exception);

        return new ExecutionError(
            type: $error->type,
            code: $error->code,
            message: $error->message,
            retryable: $error->retryable,
            details: $error->details,
            correlationId: $correlationId ?? (string) Str::uuid(),
            diagnosticId: $diagnosticId,
            provenance: $provenance ?? new FailureProvenance,
        );
    }

    private function failureWithContext(ExecutionError $failure, ?string $correlationId, ?FailureProvenance $provenance): ExecutionError
    {
        return new ExecutionError(
            type: $failure->type,
            code: $failure->code,
            message: $failure->message,
            retryable: $failure->retryable,
            details: $failure->details,
            correlationId: $correlationId ?? $failure->correlationId ?? (string) Str::uuid(),
            diagnosticId: $failure->diagnosticId,
            provenance: $provenance ?? $failure->provenance,
        );
    }

    private function map(Throwable $exception): ExecutionError
    {
        return match (true) {
            $exception instanceof AuthenticationException => new ExecutionError(
                ExecutionErrorType::Authentication,
                FailureCode::AUTHENTICATION_REQUIRED,
                'Authentication is required.',
            ),
            $exception instanceof AuthorizationException => new ExecutionError(
                ExecutionErrorType::Authorization,
                FailureCode::AUTHORIZATION_DENIED,
                'The requested operation is not authorized.',
            ),
            $exception instanceof ValidationException => new ExecutionError(
                ExecutionErrorType::Validation,
                FailureCode::VALIDATION_FAILED,
                'The request failed validation.',
                details: ['fields' => $this->validationDetails($exception)],
            ),
            $exception instanceof HttpExceptionInterface => match ($exception->getStatusCode()) {
                401 => new ExecutionError(ExecutionErrorType::Authentication, FailureCode::AUTHENTICATION_REQUIRED, 'Authentication is required.'),
                403 => new ExecutionError(ExecutionErrorType::Authorization, FailureCode::AUTHORIZATION_DENIED, 'The requested operation is not authorized.'),
                404 => new ExecutionError(ExecutionErrorType::Resource, FailureCode::RESOURCE_NOT_FOUND, 'The requested resource was not found.'),
                409 => new ExecutionError(ExecutionErrorType::Conflict, FailureCode::CONFLICT_DETECTED, 'The operation conflicts with existing authoritative data.'),
                422 => new ExecutionError(ExecutionErrorType::Validation, FailureCode::VALIDATION_FAILED, 'The request failed validation.'),
                429 => new ExecutionError(ExecutionErrorType::External, FailureCode::EXTERNAL_RATE_LIMITED, 'The external service rate limit was reached.', retryable: true),
                503 => new ExecutionError(ExecutionErrorType::External, FailureCode::EXTERNAL_UNAVAILABLE, 'The external service is currently unavailable.', retryable: true),
                504 => new ExecutionError(ExecutionErrorType::External, FailureCode::EXTERNAL_TIMEOUT, 'The external service did not complete the request in time.', retryable: true),
                default => new ExecutionError(ExecutionErrorType::Internal, FailureCode::INTERNAL_UNEXPECTED, 'The operation could not be completed.'),
            },
            $exception instanceof ModelNotFoundException => new ExecutionError(
                ExecutionErrorType::Resource,
                FailureCode::RESOURCE_NOT_FOUND,
                'The requested resource was not found.',
            ),
            $exception instanceof QueryException => $this->databaseFailure($exception),
            $exception instanceof PDOException => $this->databaseFailure($exception),
            $exception instanceof TimeoutExceededException => new ExecutionError(
                ExecutionErrorType::Queue,
                FailureCode::QUEUE_TIMEOUT,
                'The queued operation timed out.',
                retryable: true,
            ),
            $exception instanceof MaxAttemptsExceededException => new ExecutionError(
                ExecutionErrorType::Queue,
                FailureCode::QUEUE_FAILED,
                'The queued operation exhausted its retry attempts.',
            ),
            $exception instanceof RequestException => $this->httpFailure($exception),
            $exception instanceof ConnectionException => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_UNAVAILABLE,
                'The external service is currently unavailable.',
                retryable: true,
            ),
            $exception instanceof ModelProviderException => $this->modelProviderFailure($exception),
            $exception instanceof CanvaClientException => $this->externalFailure(
                $exception->failureCode,
                $exception->retryable,
            ),
            $exception instanceof PublishingProviderException => $this->externalFailure(
                $exception->failureCode,
                $exception->retryable,
            ),
            $exception instanceof JsonException => new ExecutionError(
                ExecutionErrorType::Serialization,
                FailureCode::SERIALIZATION_FAILED,
                'The operation could not safely serialize its data.',
            ),
            $exception instanceof InvalidArgumentException => new ExecutionError(
                ExecutionErrorType::Validation,
                FailureCode::VALIDATION_FAILED,
                'The supplied value is invalid.',
            ),
            $exception instanceof \RuntimeException && str_contains(strtolower($exception->getMessage()), 'timeout') => new ExecutionError(
                ExecutionErrorType::Lifecycle,
                FailureCode::LIFECYCLE_TIMEOUT,
                'The Agent execution timed out.',
                retryable: false,
            ),
            $exception instanceof LogicException => new ExecutionError(
                ExecutionErrorType::BusinessRule,
                FailureCode::BUSINESS_RULE_REJECTED,
                'The operation was rejected by a business rule.',
            ),
            default => new ExecutionError(
                ExecutionErrorType::Internal,
                FailureCode::INTERNAL_UNEXPECTED,
                'The operation could not be completed.',
            ),
        };
    }

    private function databaseFailure(QueryException|PDOException $exception): ExecutionError
    {
        $sqlState = (string) ($exception instanceof QueryException ? ($exception->errorInfo[0] ?? $exception->getCode()) : $exception->getCode());
        if (str_starts_with($sqlState, '23')) {
            return new ExecutionError(
                ExecutionErrorType::Conflict,
                FailureCode::CONFLICT_DETECTED,
                'The operation conflicts with existing authoritative data.',
            );
        }

        $retryable = in_array($sqlState, ['40001', '40P01', '1213'], true);

        return new ExecutionError(
            ExecutionErrorType::Persistence,
            FailureCode::PERSISTENCE_FAILED,
            'The operation could not persist its authoritative state.',
            retryable: $retryable,
        );
    }

    private function httpFailure(RequestException $exception): ExecutionError
    {
        $status = $exception->response->status();

        return match (true) {
            $status === 401 => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_AUTHENTICATION,
                'The external service rejected authentication.',
            ),
            $status === 403 => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_AUTHORIZATION,
                'The external service rejected authorization.',
            ),
            $status === 408, $status === 504 => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_TIMEOUT,
                'The external service did not complete the request in time.',
                retryable: true,
            ),
            $status === 429 => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_RATE_LIMITED,
                'The external service rate limit was reached.',
                retryable: true,
            ),
            $status >= 500 => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_UNAVAILABLE,
                'The external service is currently unavailable.',
                retryable: true,
            ),
            default => new ExecutionError(
                ExecutionErrorType::External,
                FailureCode::EXTERNAL_REJECTED,
                'The external service rejected the request.',
            ),
        };
    }

    private function modelProviderFailure(ModelProviderException $exception): ExecutionError
    {
        $code = match ($exception->type->value) {
            'configuration' => FailureCode::PROVIDER_CONFIGURATION,
            'timeout' => FailureCode::PROVIDER_TIMEOUT,
            'rate_limited' => FailureCode::PROVIDER_RATE_LIMITED,
            'unavailable' => FailureCode::PROVIDER_UNAVAILABLE,
            'invalid_response' => FailureCode::PROVIDER_INVALID_RESPONSE,
            default => FailureCode::PROVIDER_REJECTED,
        };

        return new ExecutionError(
            ExecutionErrorType::Provider,
            $code,
            'The model provider could not complete the execution.',
            retryable: in_array($exception->type->value, ['timeout', 'rate_limited', 'unavailable'], true),
        );
    }

    private function externalFailure(string $failureCode, bool $retryable): ExecutionError
    {
        $code = match ($failureCode) {
            'timeout' => FailureCode::EXTERNAL_TIMEOUT,
            'rate_limited' => FailureCode::EXTERNAL_RATE_LIMITED,
            'provider_unavailable' => FailureCode::EXTERNAL_UNAVAILABLE,
            'invalid_provider_response' => FailureCode::EXTERNAL_INVALID_RESPONSE,
            'credentials_unavailable', 'invalid_connection' => FailureCode::EXTERNAL_AUTHENTICATION,
            default => FailureCode::EXTERNAL_REJECTED,
        };

        return new ExecutionError(
            ExecutionErrorType::External,
            $code,
            'The external service could not complete the operation.',
            retryable: $retryable,
        );
    }

    /** @return array<string, array<int, string>> */
    private function validationDetails(ValidationException $exception): array
    {
        return array_map(
            static fn (array $messages): array => [reset($messages) ?: 'Invalid value.'],
            $exception->errors(),
        );
    }

    private function recordDiagnostic(
        Throwable $exception,
        string $diagnosticId,
        ?string $correlationId,
        ?FailureProvenance $provenance,
    ): void {
        if (! app()->bound('log')) {
            return;
        }

        Log::error('CR8OR failure diagnostic.', [
            'diagnostic_id' => $diagnosticId,
            'correlation_id' => $correlationId,
            'provenance' => $provenance?->toArray() ?? [],
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $exception->getTraceAsString(),
        ]);
    }
}