<?php

use App\Agents\MarketingAgent;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\AgentSemanticMemoryVersion;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentSemanticMemoryService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

it('creates semantic memory with scoped provenance and creation history', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create();
    $agent = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

    $memory = app(AgentSemanticMemoryService::class)->remember(
        $user,
        $enterprise,
        $agent,
        'The enterprise prefers concise campaign copy.',
        0.85,
        ['source_type' => AgentExecution::class, 'source_id' => $execution->getKey()],
    );

    expect($memory->enterprise_id)->toBe($enterprise->getKey())
        ->and($memory->agent_descriptor_id)->toBe($agent->getKey())
        ->and($memory->statement)->toBe('The enterprise prefers concise campaign copy.')
        ->and($memory->confidence)->toBe(0.85)
        ->and($memory->status)->toBe(AgentSemanticMemory::STATUS_ACTIVE)
        ->and($memory->provenance)->toMatchArray([
            'source_type' => AgentExecution::class,
            'source_id' => $execution->getKey(),
        ])
        ->and($memory->versions)->toHaveCount(1)
        ->and($memory->versions->first()->change_type)->toBe('created');
});

it('updates semantic memory without destroying its prior version', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create();
    $agent = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

    $service = app(AgentSemanticMemoryService::class);
    $memory = $service->remember($user, $enterprise, $agent, 'Short copy is preferred.', 0.7, [
        'source_type' => AgentExecution::class,
        'source_id' => $execution->getKey(),
    ]);

    $service->update($user, $memory, 'Short copy with a direct CTA is preferred.', 0.92, [
        'source_type' => AgentExecution::class,
        'source_id' => $execution->getKey(),
    ]);

    $memory->refresh();

    expect($memory->statement)->toBe('Short copy with a direct CTA is preferred.')
        ->and($memory->versions()->orderBy('id')->pluck('statement')->all())->toBe([
            'Short copy is preferred.',
            'Short copy with a direct CTA is preferred.',
        ])
        ->and($memory->versions()->orderBy('id')->pluck('change_type')->all())->toBe(['created', 'updated']);
});

it('marks conflicting memories as disputed while preserving both statements and histories', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $executionA = AgentExecution::factory()->forEnterprise($enterprise)->create();
    $executionB = AgentExecution::factory()->forEnterprise($enterprise)->create();
    $agent = AgentDescriptor::query()->findOrFail($executionA->agent_descriptor_id);

    $service = app(AgentSemanticMemoryService::class);
    $first = $service->remember($user, $enterprise, $agent, 'The audience prefers long-form content.', 0.8, [
        'source_type' => AgentExecution::class,
        'source_id' => $executionA->getKey(),
    ]);
    $second = $service->remember($user, $enterprise, $agent, 'The audience prefers short-form content.', 0.8, [
        'source_type' => AgentExecution::class,
        'source_id' => $executionB->getKey(),
    ]);

    $service->recordConflict($user, $first, $second);
    $first->refresh();
    $second->refresh();

    expect($first->status)->toBe(AgentSemanticMemory::STATUS_DISPUTED)
        ->and($second->status)->toBe(AgentSemanticMemory::STATUS_DISPUTED)
        ->and($first->statement)->toBe('The audience prefers long-form content.')
        ->and($second->statement)->toBe('The audience prefers short-form content.')
        ->and($first->conflict_memory_ids)->toContain($second->getKey())
        ->and($second->conflict_memory_ids)->toContain($first->getKey())
        ->and($first->versions()->count())->toBe(2)
        ->and($second->versions()->count())->toBe(2);
});

it('rejects conflicts across Enterprise or Agent boundaries', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);

    $first = AgentSemanticMemory::factory()->forEnterprise($enterprise)->create();
    $foreign = AgentSemanticMemory::factory()->forEnterprise($foreignEnterprise)->create();

    expect(fn () => app(AgentSemanticMemoryService::class)->recordConflict($user, $first, $foreign))
        ->toThrow(AuthorizationException::class);
});

it('denies semantic memory access outside the users organization', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::factory()->create();

    expect(fn () => app(AgentSemanticMemoryService::class)->retrieve($user, $foreignEnterprise, $agent))
        ->toThrow(AuthorizationException::class);
});

it('bounds semantic memory retrieval to the authorized Enterprise and Agent', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::factory()->create();
    $otherAgent = AgentDescriptor::factory()->forRuntimeClass(MarketingAgent::class)->create();

    AgentSemanticMemory::factory()->forAgent($agent, $enterprise)->count(110)->create();
    AgentSemanticMemory::factory()->forAgent($otherAgent, $enterprise)->count(3)->create();
    AgentSemanticMemory::factory()->forEnterprise($foreignEnterprise)->count(3)->create();

    $memories = app(AgentSemanticMemoryService::class)->retrieve($user, $enterprise, $agent, null, 1000);

    expect($memories)->toHaveCount(100)
        ->and($memories->every(fn (AgentSemanticMemory $memory): bool => $memory->enterprise_id === $enterprise->getKey()
            && $memory->agent_descriptor_id === $agent->getKey()
        ))->toBeTrue();
});

it('rejects invalid confidence and missing provenance', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::factory()->create();

    expect(fn () => app(AgentSemanticMemoryService::class)->remember(
        $user, $enterprise, $agent, 'Statement', 1.1, [],
    ))->toThrow(InvalidArgumentException::class);
});

it('keeps semantic memory distinct from episodic execution history', function () {
    $memory = AgentSemanticMemory::factory()->create();

    expect($memory->toArray())->not->toHaveKey('execution_id')
        ->and($memory->toArray())->not->toHaveKey('objective')
        ->and($memory->toArray())->not->toHaveKey('action')
        ->and($memory->toArray())->not->toHaveKey('outcome')
        ->and($memory->versions()->first())->toBeInstanceOf(AgentSemanticMemoryVersion::class);
});
