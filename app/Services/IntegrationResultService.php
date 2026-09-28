<?php

namespace App\Services;

use App\Contracts\IntegrationResultFetcher;
use App\Data\Integrations\IntegrationResultEnvelope;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class IntegrationResultService
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    public function ingest(string $provider, string $source, IntegrationResultEnvelope $envelope): IntegrationResult
    {
        if (! in_array($source, ['webhook', 'poll'], true)) {
            throw new LogicException("Unsupported integration result source [{$source}].");
        }

        $job = $this->locateJob($provider, $envelope);
        $providerDefinition = $this->registry->provider($provider);
        $this->registry->assertOperation($providerDefinition->integration, $provider, $job->operation);

        $dedupeKey = $envelope->dedupeKey($provider);
        $existing = IntegrationResult::query()->where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $apply = $this->shouldApply($job, $envelope->status);

        try {
            return DB::transaction(function () use ($job, $provider, $source, $envelope, $dedupeKey, $apply): IntegrationResult {
                $result = IntegrationResult::query()->create([
                    'integration_job_id' => $job->id,
                    'integration_connection_id' => $job->integration_connection_id,
                    'organization_id' => $job->organization_id,
                    'enterprise_id' => $job->enterprise_id,
                    'provider' => $provider,
                    'operation' => $job->operation,
                    'external_job_id' => $envelope->externalJobId,
                    'external_result_id' => $envelope->externalResultId,
                    'status' => $envelope->status,
                    'source' => $source,
                    'dedupe_key' => $dedupeKey,
                    'correlation_id' => $envelope->correlationId ?? $job->correlation_id,
                    'payload' => $envelope->payload,
                    'failure_code' => $envelope->failureCode,
                    'failure_reason' => $envelope->failureReason,
                    'occurred_at' => $envelope->occurredAt,
                    'received_at' => now(),
                    'processed_at' => now(),
                    'processing_status' => $apply
                        ? IntegrationResult::PROCESSING_APPLIED
                        : IntegrationResult::PROCESSING_IGNORED,
                ]);

                if ($apply) {
                    $job->reconcile(
                        $envelope->status,
                        $envelope->failureCode,
                        $envelope->failureReason,
                        $envelope->externalJobId,
                    )->save();
                }

                return $result;
            });
        } catch (QueryException $e) {
            if (IntegrationResult::query()->where('dedupe_key', $dedupeKey)->exists()) {
                return IntegrationResult::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
            }

            throw $e;
        }
    }

    public function poll(IntegrationJob $job, IntegrationResultFetcher $fetcher): ?IntegrationResult
    {
        $envelope = $fetcher->fetch($job);

        return $envelope === null ? null : $this->ingest($job->provider, 'poll', $envelope);
    }

    private function locateJob(string $provider, IntegrationResultEnvelope $envelope): IntegrationJob
    {
        $byExternal = IntegrationJob::query()
            ->where('provider', $provider)
            ->where('external_job_id', $envelope->externalJobId)
            ->get();

        $byCorrelation = $envelope->correlationId === null
            ? collect()
            : IntegrationJob::query()
                ->where('provider', $provider)
                ->where('correlation_id', $envelope->correlationId)
                ->get();

        $matches = $byExternal->merge($byCorrelation)->unique('id');

        if ($matches->count() !== 1) {
            throw new ModelNotFoundException('External result could not be correlated to exactly one CR8OR integration job.');
        }

        return $matches->firstOrFail();
    }

    private function shouldApply(IntegrationJob $job, string $status): bool
    {
        if ($job->status === $status) {
            return false;
        }

        if ($job->status === IntegrationJob::STATUS_UNKNOWN) {
            return true;
        }

        return in_array($job->status, [
            IntegrationJob::STATUS_PENDING,
            IntegrationJob::STATUS_RUNNING,
        ], true);
    }
}