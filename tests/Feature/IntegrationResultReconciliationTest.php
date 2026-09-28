<?php

use App\Contracts\IntegrationResultFetcher;
use App\Data\Integrations\IntegrationResultEnvelope;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\IntegrationResultService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function integrationResultContext(string $provider = 'canva'): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => $provider,
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $operation = $provider === 'postiz' ? 'publication.publish' : 'design.create';
    $job = IntegrationJob::query()->create([
        'integration_connection_id' => $connection->id,
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => $provider,
        'operation' => $operation,
        'idempotency_key' => 'job-'.$provider.'-1',
        'status' => IntegrationJob::STATUS_PENDING,
        'external_job_id' => 'external-job-1',
        'correlation_id' => 'correlation-1',
        'attempts' => 1,
    ]);

    return [$organization, $user, $enterprise, $connection, $job];
}

function resultEnvelope(string $status, string $externalResultId = 'result-1', ?string $correlationId = 'correlation-1'): IntegrationResultEnvelope
{
    return new IntegrationResultEnvelope(
        'external-job-1',
        $externalResultId,
        $status,
        $correlationId,
        'delivery-1',
        CarbonImmutable::parse('2026-09-28T06:00:00Z'),
        ['provider_status' => $status],
        $status === 'failed' ? 'provider_failed' : null,
        $status === 'failed' ? 'Provider reported failure.' : null,
    );
}

it('applies a successful external result through the integration job lifecycle', function () {
    [, , $enterprise, $connection, $job] = integrationResultContext();

    $result = app(IntegrationResultService::class)->ingest('canva', 'webhook', resultEnvelope('succeeded'));

    expect($result->processing_status)->toBe(IntegrationResult::PROCESSING_APPLIED)
        ->and($result->enterprise_id)->toBe($enterprise->id)
        ->and($result->integration_connection_id)->toBe($connection->id)
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED)
        ->and($job->external_job_id)->toBe('external-job-1');
});

it('deduplicates webhook replay by stable provider result identity', function () {
    [, , , , $job] = integrationResultContext();

    $first = app(IntegrationResultService::class)->ingest('canva', 'webhook', resultEnvelope('succeeded'));
    $second = app(IntegrationResultService::class)->ingest('canva', 'webhook', resultEnvelope('succeeded'));

    expect($second->id)->toBe($first->id)
        ->and(IntegrationResult::query()->where('integration_job_id', $job->id)->count())->toBe(1)
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('records late and out-of-order results without overwriting terminal CR8OR state', function () {
    [, , , , $job] = integrationResultContext();

    app(IntegrationResultService::class)->ingest('canva', 'webhook', resultEnvelope('succeeded', 'result-success'));

    $late = app(IntegrationResultService::class)->ingest(
        'canva',
        'webhook',
        resultEnvelope('failed', 'result-failure'),
    );

    expect($late->processing_status)->toBe(IntegrationResult::PROCESSING_IGNORED)
        ->and(IntegrationResult::query()->count())->toBe(2)
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('resolves an unknown job result deterministically when a later provider result arrives', function () {
    [, , , , $job] = integrationResultContext();
    $job->status = IntegrationJob::STATUS_UNKNOWN;
    $job->save();

    $result = app(IntegrationResultService::class)->ingest('canva', 'poll', resultEnvelope('succeeded', 'result-recovered'));

    expect($result->processing_status)->toBe(IntegrationResult::PROCESSING_APPLIED)
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('supports polling through a provider-neutral result fetcher contract', function () {
    [, , , , $job] = integrationResultContext();

    $fetcher = new class implements IntegrationResultFetcher
    {
        public function fetch(IntegrationJob $job): ?IntegrationResultEnvelope
        {
            return resultEnvelope('succeeded', 'poll-result');
        }
    };

    $result = app(IntegrationResultService::class)->poll($job, $fetcher);

    expect($result)->not->toBeNull()
        ->and($result?->source)->toBe('poll')
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('rejects ambiguous correlation rather than risking cross-tenant mutation', function () {
    [, , $enterprise, $connection, $job] = integrationResultContext();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $enterprise->organization_id]);
    $otherConnection = IntegrationConnection::query()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $otherEnterprise->id,
        'provider' => 'canva',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    IntegrationJob::query()->create([
        'integration_connection_id' => $otherConnection->id,
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $otherEnterprise->id,
        'provider' => 'canva',
        'operation' => 'design.create',
        'idempotency_key' => 'job-canva-2',
        'status' => IntegrationJob::STATUS_PENDING,
        'external_job_id' => 'external-job-1',
        'correlation_id' => 'correlation-1',
        'attempts' => 1,
    ]);

    expect(fn () => app(IntegrationResultService::class)->ingest('canva', 'webhook', resultEnvelope('succeeded')))
        ->toThrow(ModelNotFoundException::class)
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_PENDING);
});

it('authenticates webhook requests before accepting provider results', function () {
    [, , , , $job] = integrationResultContext();
    config(['services.integrations.webhooks.secrets.canva' => 'test-secret']);

    $payload = [
        'external_job_id' => $job->external_job_id,
        'external_result_id' => 'webhook-result',
        'status' => 'succeeded',
        'correlation_id' => $job->correlation_id,
        'delivery_id' => 'delivery-http-1',
        'occurred_at' => '2026-09-28T06:00:00Z',
    ];

    $response = $this->postJson('/integrations/webhooks/canva', $payload, [
        'X-CR8OR-Signature' => 'sha256=invalid',
    ]);

    $response->assertUnauthorized();
    expect($job->refresh()->status)->toBe(IntegrationJob::STATUS_PENDING);
});

it('accepts a signed webhook and normalizes the result envelope', function () {
    [, , , , $job] = integrationResultContext();
    config(['services.integrations.webhooks.secrets.canva' => 'test-secret']);

    $payload = [
        'external_job_id' => $job->external_job_id,
        'external_result_id' => 'webhook-result',
        'status' => 'succeeded',
        'correlation_id' => $job->correlation_id,
        'delivery_id' => 'delivery-http-1',
        'occurred_at' => '2026-09-28T06:00:00Z',
    ];

    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $response = $this->postJson('/integrations/webhooks/canva', $payload, [
        'X-CR8OR-Signature' => $signature,
    ]);

    $response->assertAccepted()->assertJsonPath('processing_status', IntegrationResult::PROCESSING_APPLIED);
    expect($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('preserves the original provider payload and historical result identity', function () {
    [, , , , $job] = integrationResultContext();

    $result = app(IntegrationResultService::class)->ingest('canva', 'poll', resultEnvelope('failed', 'failure-result'));

    expect($result->payload)->toBe(['provider_status' => 'failed'])
        ->and($result->external_result_id)->toBe('failure-result')
        ->and($result->correlation_id)->toBe($job->correlation_id)
        ->and($result->occurred_at->toIso8601String())->toBe('2026-09-28T06:00:00+00:00');
});