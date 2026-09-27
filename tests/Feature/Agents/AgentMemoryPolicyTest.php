<?php

use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentMemoryPolicy;
use App\Services\AgentMemoryService;
use Illuminate\Auth\Access\AuthorizationException;

it('denies semantic memory writes from failed executions', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_FAILED,
        'failure_reason' => 'Provider failure',
        'completed_at' => now(),
    ]);

    expect(fn () => app(AgentMemoryPolicy::class)->authorizeSemanticWrite($user, $execution))
        ->toThrow(AuthorizationException::class);
});

it('denies episodic memory writes from non-terminal executions', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->executing()->create();

    expect(fn () => app(AgentMemoryPolicy::class)->authorizeEpisodicWrite($user, $execution))
        ->toThrow(AuthorizationException::class);
});

it('denies memory writes for a disabled Agent assignment', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->disabled()->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);

    expect(fn () => app(AgentMemoryPolicy::class)->authorizeSemanticWrite($user, $execution))
        ->toThrow(AuthorizationException::class);
});

it('denies memory reads for a disabled Agent descriptor', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $agent = AgentDescriptor::factory()->disabled()->create();

    expect(fn () => app(AgentMemoryPolicy::class)->authorizeRead($user, $enterprise, $agent))
        ->toThrow(AuthorizationException::class);
});

it('retrieves both memory types through one bounded governed boundary', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $agent = AgentDescriptor::factory()->create();

    AgentEpisodicMemory::factory()->forAgent($agent, $enterprise)->count(55)->create();
    AgentSemanticMemory::factory()->forAgent($agent, $enterprise)->count(105)->create();

    $memory = app(AgentMemoryService::class)->retrieve(
        $user,
        $enterprise,
        $agent,
        episodicLimit: 1000,
        semanticLimit: 1000,
    );

    expect($memory['episodic'])->toHaveCount(50)
        ->and($memory['semantic'])->toHaveCount(100)
        ->and($memory['episodic']->every(fn (AgentEpisodicMemory $item): bool => $item->enterprise_id === $enterprise->getKey()
            && $item->agent_descriptor_id === $agent->getKey()
            && isset($item->provenance['source_type'], $item->provenance['source_id'])))->toBeTrue()
        ->and($memory['semantic']->every(fn (AgentSemanticMemory $item): bool => $item->enterprise_id === $enterprise->getKey()
            && $item->agent_descriptor_id === $agent->getKey()
            && isset($item->provenance['source_type'], $item->provenance['source_id'])))->toBeTrue();
});
