<?php

use App\Agents\FinanceAgent;
use App\AI\Contracts\ModelProvider;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentRuntimePolicy;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentExecutionService;
use App\Services\AgentRuntimePolicyService;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

function runtimePolicyActors(): array
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

it('resolves runtime policy by increasing scope specificity', function () {
    [$organization, $enterprise] = runtimePolicyActors();
    $agent = AgentDescriptor::factory()->create();
    $expert = App\Models\ExpertDescriptor::factory()->create();

    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'enabled' => true,
        'max_steps' => 8,
        'max_retries' => 5,
    ]);
    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'max_steps' => 6,
    ]);
    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'max_steps' => 4,
    ]);
    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'agent_descriptor_id' => $agent->id,
        'max_steps' => 3,
    ]);
    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'expert_descriptor_id' => $expert->id,
        'max_retries' => 1,
    ]);

    $policy = app(AgentRuntimePolicyService::class)->resolve(
        $organization->id,
        $enterprise->id,
        $agent->id,
        $expert->id,
    );

    expect($policy['max_steps'])->toBe(3)
        ->and($policy['max_retries'])->toBe(1);
});

it('fails closed for unsafe limits and options', function () {
    [$organization] = runtimePolicyActors();

    expect(fn () => AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'max_steps' => 0,
    ]))->toThrow(LogicException::class);

    $service = app(AgentRuntimePolicyService::class);
    $policy = [
        ...config('agent_runtime.defaults'),
        'environment' => config('agent_runtime.environment'),
        'max_steps' => 2,
        'max_retries' => 1,
        'timeout_seconds' => 30,
    ];

    expect(fn () => $service->enforceOptions($policy, ['max_steps' => 3]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $service->enforceContextSize(
        ['max_context_bytes' => 10],
        ['payload' => str_repeat('x', 100)],
    ))->toThrow(AuthorizationException::class);
});

it('enforces disabled runtime policy without granting any authority', function () {
    [$organization, $enterprise] = runtimePolicyActors();

    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'enabled' => false,
    ]);

    expect(fn () => app(AgentRuntimePolicyService::class)->assertCanExecute(
        app(AgentRuntimePolicyService::class)->resolve($organization->id, $enterprise->id),
    ))->toThrow(AuthorizationException::class);
});

it('persists the effective runtime policy on each new execution', function () {
    [$organization, $enterprise, $user] = runtimePolicyActors();
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(FinanceAgent::class)->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->id,
    ]);

    AgentRuntimePolicy::query()->create([
        'environment' => config('agent_runtime.environment'),
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'agent_descriptor_id' => $descriptor->id,
        'max_steps' => 2,
        'max_retries' => 1,
        'timeout_seconds' => 30,
        'max_context_bytes' => 120000,
    ]);

    app()->instance(ModelProvider::class, FakeModelProvider::returning());

    $execution = app(AgentExecutionService::class)->execute(new AgentExecutionRequest(
        actor: $user,
        assignment: $assignment,
        prompt: 'Produce a financial summary.',
        options: ['max_steps' => 2],
        idempotencyKey: 'runtime-policy-test',
    ))->execution;

    expect($execution->max_steps)->toBe(2)
        ->and($execution->max_retries)->toBe(1)
        ->and($execution->runtime_policy['max_steps'])->toBe(2)
        ->and($execution->runtime_policy_version)->toHaveLength(64)
        ->and($execution->model_options['max_steps'])->toBe(2);
});

it('allows the authorized policy writer to upsert an Enterprise override', function () {
    [$organization, $enterprise, $user] = runtimePolicyActors();

    $policy = app(AgentRuntimePolicyService::class)->upsert($user, [
        'environment' => config('agent_runtime.environment'),
        'enterprise_id' => $enterprise->id,
        'max_steps' => 2,
        'max_retries' => 1,
    ]);

    expect($policy->organization_id)->toBe($organization->id)
        ->and($policy->enterprise_id)->toBe($enterprise->id)
        ->and($policy->max_steps)->toBe(2);
});