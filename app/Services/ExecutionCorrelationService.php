<?php

namespace App\Services;

use App\AI\Contracts\ExecutionError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Request as McpRequest;

final class ExecutionCorrelationService
{
    public const HEADER = 'X-Correlation-ID';

    public function resolve(?string $candidate = null): string
    {
        $candidate = trim((string) ($candidate ?? ''));

        if ($candidate === '' && app()->bound('request')) {
            $candidate = trim((string) request()->attributes->get('cr8or.correlation_id', ''));
            if ($candidate === '') {
                $candidate = trim((string) request()->header(self::HEADER, ''));
            }
        }

        if ($candidate !== '' && strlen($candidate) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }

    public function forMcp(McpRequest $request): string
    {
        $meta = $request->meta() ?? [];
        $candidate = data_get($meta, 'cr8or.correlation_id') ?? data_get($meta, 'correlation_id');
        $correlationId = $this->resolve(is_string($candidate) ? $candidate : null);

        if (app()->bound('request')) {
            request()->attributes->set('cr8or.correlation_id', $correlationId);
        }

        return $correlationId;
    }

    /** @param array<string, mixed> $context */
    public function logFailure(string $operation, string $correlationId, ExecutionError $error, array $context = []): void
    {
        Log::warning('CR8OR execution failed.', array_merge([
            'operation' => $operation,
            'correlation_id' => $correlationId,
            'failure_type' => $error->type->value,
            'failure_code' => $error->code,
            'retryable' => $error->retryable,
        ], $context));
    }
}
