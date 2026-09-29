<?php

namespace App\AI\Contracts;

use Illuminate\Support\Str;
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

    /**
     * Compatibility entry point for existing callers.
     *
     * The authoritative exception-to-failure mapping lives in FailureTranslator.
     */
    public static function from(
        Throwable $exception,
        ?string $correlationId = null,
        ?FailureProvenance $provenance = null,
    ): self {
        if (app()->bound(\App\Services\FailureTranslator::class)) {
            return app(\App\Services\FailureTranslator::class)->translate($exception, $correlationId, $provenance);
        }

        return new \App\Services\FailureTranslator()->translate($exception, $correlationId, $provenance);
    }

    /** @return array<string, mixed> */
    public function toArray(?string $correlationId = null): array
    {
        $resolvedCorrelationId = $correlationId ?? $this->correlationId ?? (string) Str::uuid();

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
}