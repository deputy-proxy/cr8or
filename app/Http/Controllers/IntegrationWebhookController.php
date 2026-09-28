<?php

namespace App\Http\Controllers;

use App\Contracts\IntegrationWebhookVerifier;
use App\Data\Integrations\IntegrationResultEnvelope;
use App\Services\ExecutionCorrelationService;
use App\Services\IntegrationResultService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class IntegrationWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        IntegrationWebhookVerifier $verifier,
        IntegrationResultService $results,
        ExecutionCorrelationService $correlation,
    ): JsonResponse {
        $verifier->verify($provider, $request);

        $payload = $request->json()->all();

        if ($payload === []) {
            throw ValidationException::withMessages(['payload' => 'Webhook payload must be a non-empty JSON object.']);
        }

        $externalJobId = data_get($payload, 'external_job_id');
        $status = data_get($payload, 'status');

        if (! is_string($externalJobId) || $externalJobId === '' || ! is_string($status)) {
            throw ValidationException::withMessages([
                'external_job_id' => 'An external job identifier is required.',
                'status' => 'A normalized result status is required.',
            ]);
        }

        $occurredAt = data_get($payload, 'occurred_at');

        try {
            $occurredAt = $occurredAt === null ? CarbonImmutable::now() : CarbonImmutable::parse($occurredAt);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['occurred_at' => 'Webhook occurred_at must be a valid timestamp.']);
        }

        $result = $results->ingest(
            $provider,
            'webhook',
            new IntegrationResultEnvelope(
                $externalJobId,
                is_string(data_get($payload, 'external_result_id')) ? data_get($payload, 'external_result_id') : null,
                $status,
                is_string(data_get($payload, 'correlation_id')) ? data_get($payload, 'correlation_id') : $correlation->resolve(),
                is_string(data_get($payload, 'delivery_id')) ? data_get($payload, 'delivery_id') : $request->header('X-Delivery-ID'),
                $occurredAt,
                $payload,
                is_string(data_get($payload, 'failure_code')) ? data_get($payload, 'failure_code') : null,
                is_string(data_get($payload, 'failure_reason')) ? data_get($payload, 'failure_reason') : null,
            ),
        );

        return response()->json([
            'result_id' => $result->id,
            'status' => $result->status,
            'processing_status' => $result->processing_status,
        ], 202);
    }
}