<?php

use App\Jobs\RunAgentExecutionJob;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentExecutionHealthService;
use Illuminate\Support\Carbon;

function reliabilityActors(): array
{
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    return [$organization, $enterprise, $user];
}

it('reports stuck executions and distinguishes waiting states from operationally stuck work', function () {
    [, $enterprise, $user] = reliabilityActors();
    $now = Carbon::parse('2026-09-28T08:00:00Z');

    AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'organization_id' => $enterprise->organization_id,
        'status' => AgentExecution::STATUS_REQUESTED,
        'requested_at' => $now->copy()->subMinutes(10),
    ]);

    AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'status' => AgentExecution::STATUS_WAITING_FOR_APPROVAL,
        'requested_at' => $now->copy()->subHours(2),
    ]);

    AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'status' => AgentExecution::STATUS_EXECUTING,
        'requested_at' => $now->copy()->subMinutes(5),
        'started_at' => $now->copy()->subMinutes(3),
        'runtime_policy' => ['timeout_seconds' => 60],
    ]);

    $health = app(AgentExecutionHealthService::class)->summarize($user, $enterprise, $now);

    expect($health['counts']['requested'])->toBe(1)
        ->and($health['counts']['waiting_for_approval'])->toBe(1)
        ->and($health['counts']['stuck'])->toBe(2)
        ->and($health['stuck'][0]['execution_id'])->not->toBeNull();
});

it('uses persisted execution limits for queue retries and timeout', function () {
    [, $enterprise] = reliabilityActors();
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'max_retries' => 2,
        'runtime_policy' => ['timeout_seconds' => 45],
    ]);

    $job = new RunAgentExecutionJob($execution->id, $execution->actor_id);

    expect($job->tries())->toBe(2)
        ->and($job->timeout())->toBe(45)
        ->and($job->middleware())->toHaveCount(1);
});

it('keeps idempotency as the recovery boundary for repeated queue delivery', function () {
    [, $enterprise] = reliabilityActors();
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'enterprise_id' => $enterprise->id,
        'idempotency_key' => 'recovery-key',
        'status' => AgentExecution::STATUS_COMPLETED,
    ]);

    $job = new RunAgentExecutionJob($execution->id, $execution->actor_id);
    expect($job->uniqueId())->toBe('agent-execution:'.$execution->id)
        ->and(AgentExecution::query()->where('idempotency_key', 'recovery-key')->count())->toBe(1);
});