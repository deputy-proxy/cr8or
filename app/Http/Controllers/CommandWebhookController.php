<?php

namespace App\Http\Controllers;

use App\Capabilities\CapabilityRegistry;
use App\Contracts\CommandWebhookAuthenticator;
use App\Data\CapabilityInvocationRequest;
use App\Models\CommandWebhookDelivery;
use App\Services\CapabilityExecutionException;
use App\Services\CapabilityInvocationService;
use App\Services\EnterpriseIdentityResolver;
use App\Services\FailureTranslator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommandWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $capability,
        CommandWebhookAuthenticator $authenticator,
        CapabilityRegistry $registry,
        EnterpriseIdentityResolver $enterprises,
        CapabilityInvocationService $invocation,
        FailureTranslator $failures,
    ): JsonResponse {
        $identity = $authenticator->authenticate($request);
        $definition = $registry->resolve($capability);

        if ($definition->category !== 'business') {
            throw new AuthorizationException('Command webhooks may target business Capabilities only.');
        }

        if (! $identity->allows($definition->key)) {
            throw new AuthorizationException("Command webhook credential is not allowed to invoke [{$definition->key}].");
        }

        $payload = $request->json()->all();

        if ($payload === []) {
            throw ValidationException::withMessages(['payload' => 'Command webhook payload must be a non-empty JSON object.']);
        }

        $enterpriseId = isset($payload['enterprise_id']) && is_numeric($payload['enterprise_id'])
            ? (int) $payload['enterprise_id']
            : null;
        $enterpriseSlug = isset($payload['enterprise_slug']) && is_string($payload['enterprise_slug'])
            ? $payload['enterprise_slug']
            : null;

        $enterprise = $enterprises->resolve($identity->actor, $enterpriseId, $enterpriseSlug);

        $idempotencyKey = $this->stringValue(
            $payload['idempotency_key'] ?? $request->header('Idempotency-Key'),
        );
        $correlationId = $this->stringValue(
            $payload['correlation_id'] ?? $request->header('X-Correlation-ID'),
        );

        if ($idempotencyKey === null) {
            throw ValidationException::withMessages(['idempotency_key' => 'A stable idempotency key is required.']);
        }

        if ($correlationId === null) {
            throw ValidationException::withMessages(['correlation_id' => 'A correlation ID is required.']);
        }

        if ($definition->approvalRequirement === 'required') {
            throw new AuthorizationException(
                "Command webhooks cannot execute Capability [{$definition->key}] without an Agent-bound approval context.",
            );
        }

        $input = isset($payload['input']) && is_array($payload['input'])
            ? $payload['input']
            : [];

        $targetContext = isset($payload['target_context']) && is_array($payload['target_context'])
            ? $payload['target_context']
            : [];

        $delivery = $this->claimDelivery(
            $identity->keyId,
            $idempotencyKey,
            $definition->key,
            $identity->actor->getKey(),
            $enterprise->organization_id,
            $enterprise->getKey(),
            $correlationId,
            $payload,
        );

        if ($delivery->status === CommandWebhookDelivery::STATUS_SUCCEEDED) {
            return response()->json($delivery->response ?? [], 200);
        }

        if ($delivery->status === CommandWebhookDelivery::STATUS_FAILED) {
            return response()->json($delivery->response ?? [], $this->statusForFailure($delivery->failure_code));
        }

        try {
            $result = $invocation->invoke(new CapabilityInvocationRequest(
                capability: $definition->key,
                actor: $identity->actor,
                enterprise: $enterprise,
                targetContext: $targetContext,
                inputPayload: $input,
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
            ));

            $response = [
                'status' => $result['status'],
                'capability' => $result['capability'],
                'result' => $result['result'] ?? null,
                'provenance' => $result['provenance'],
            ];

            $delivery->forceFill([
                'status' => CommandWebhookDelivery::STATUS_SUCCEEDED,
                'response' => $response,
                'processed_at' => now(),
            ])->save();

            return response()->json($response, 200);
        } catch (CapabilityExecutionException $exception) {
            $failure = $failures->translate(
                $exception,
                correlationId: $correlationId,
            );
            $response = $failure->toArray();

            $delivery->forceFill([
                'status' => CommandWebhookDelivery::STATUS_FAILED,
                'response' => $response,
                'failure_code' => $failure->code,
                'failure_reason' => $failure->message,
                'processed_at' => now(),
            ])->save();

            return response()->json($response, $this->statusForFailure($failure->code));
        } catch (\Throwable $exception) {
            $failure = $failures->translate($exception, correlationId: $correlationId);
            $response = $failure->toArray();

            $delivery->forceFill([
                'status' => CommandWebhookDelivery::STATUS_FAILED,
                'response' => $response,
                'failure_code' => $failure->code,
                'failure_reason' => $failure->message,
                'processed_at' => now(),
            ])->save();

            return response()->json($response, $this->statusForFailure($failure->code));
        }
    }

    /** @param array<string, mixed> $payload */
    private function claimDelivery(
        string $keyId,
        string $idempotencyKey,
        string $capability,
        int $actorId,
        int $organizationId,
        int $enterpriseId,
        string $correlationId,
        array $payload,
    ): CommandWebhookDelivery {
        $existing = CommandWebhookDelivery::query()
            ->where('key_id', $keyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (
                $existing->capability !== $capability
                || (int) $existing->enterprise_id !== $enterpriseId
                || (int) $existing->actor_id !== $actorId
            ) {
                throw new AuthorizationException('Idempotency key is already bound to another command.');
            }

            if ($existing->status === CommandWebhookDelivery::STATUS_PENDING) {
                throw new AuthorizationException('The command webhook delivery is already being processed.');
            }

            return $existing;
        }

        try {
            return DB::transaction(fn (): CommandWebhookDelivery => CommandWebhookDelivery::query()->create([
                'key_id' => $keyId,
                'idempotency_key' => $idempotencyKey,
                'capability' => $capability,
                'actor_id' => $actorId,
                'organization_id' => $organizationId,
                'enterprise_id' => $enterpriseId,
                'correlation_id' => $correlationId,
                'payload' => $payload,
                'status' => CommandWebhookDelivery::STATUS_PENDING,
            ]));
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = CommandWebhookDelivery::query()
                ->where('key_id', $keyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function statusForFailure(?string $code): int
    {
        return match ($code) {
            'authentication.required' => 401,
            'authorization.denied', 'approval.denied', 'approval.required' => 403,
            'validation.failed' => 422,
            'resource.not_found' => 404,
            'conflict.detected' => 409,
            'external.rate_limited', 'provider.rate_limited' => 429,
            'external.unavailable', 'provider.unavailable', 'external.timeout', 'provider.timeout' => 503,
            default => 500,
        };
    }
}