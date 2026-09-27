<?php

use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentEpisodicMemoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

it('persists a meaningful episodic experience with execution provenance', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => Carbon::parse('2026-09-27 10:00:00'),
    ]);

    $memory = app(AgentEpisodicMemoryService::class)->recordMeaningfulEvent(
        $user,
        $execution,
        'Increase qualified leads.',
        'Reviewed campaign performance.',
        'Qualified leads increased.',
        'The campaign direction was retained.',
        'campaign performance',
    );

    expect($memory)->toBeInstanceOf(AgentEpisodicMemory::class)
        ->and($memory->organization_id)->toBe($enterprise->organization_id)
        ->and($memory->enterprise_id)->toBe($enterprise->getKey())
        ->and($memory->agent_descriptor_id)->toBe($execution->agent_descriptor_id)
        ->and($memory->execution_id)->toBe($execution->getKey())
        ->and($memory->topic)->toBe('campaign performance')
        ->and($memory->objective)->toBe('Increase qualified leads.')
        ->and($memory->action)->toBe('Reviewed campaign performance.')
        ->and($memory->result)->toBe('Qualified leads increased.')
        ->and($memory->outcome)->toBe('The campaign direction was retained.')
        ->and($memory->occurred_at->toISOString())->toBe('2026-09-27T10:00:00.000000Z')
        ->and($memory->provenance)->toMatchArray([
            'source_type' => AgentExecution::class,
            'source_id' => $execution->getKey(),
        ]);
});

it('does not record an event when meaningful content is missing', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create();

    expect(fn () => app(AgentEpisodicMemoryService::class)->recordMeaningfulEvent(
        $user,
        $execution,
        '',
        'Reviewed campaign performance.',
        'Qualified leads increased.',
        'The campaign direction was retained.',
    ))->toThrow(InvalidArgumentException::class);

    expect(AgentEpisodicMemory::query()->count())->toBe(0);
});

it('rejects memory whose provenance points at a different source record', function () {
    $execution = AgentExecution::factory()->forEnterprise()->create();

    expect(fn () => AgentEpisodicMemory::factory()->forExecution($execution)->create([
        'provenance' => [
            'source_type' => AgentExecution::class,
            'source_id' => $execution->getKey() + 999,
        ],
    ]))->toThrow(\LogicException::class);
});

it('retrieves deterministically by Enterprise, Agent, topic and relevance window', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $agent = AgentDescriptor::factory()->create();

    $older = AgentEpisodicMemory::factory()->forAgent($agent, $enterprise)->create([
        'topic' => 'other',
        'occurred_at' => Carbon::parse('2026-09-25 10:00:00'),
    ]);
    $matching = AgentEpisodicMemory::factory()->forAgent($agent, $enterprise)->create([
        'topic' => 'campaign',
        'occurred_at' => Carbon::parse('2026-09-26 10:00:00'),
    ]);
    $newer = AgentEpisodicMemory::factory()->forAgent($agent, $enterprise)->create([
        'topic' => 'campaign',
        'occurred_at' => Carbon::parse('2026-09-27 10:00:00'),
    ]);

    $memories = app(AgentEpisodicMemoryService::class)->retrieve(
        $user,
        $enterprise,
        $agent,
        'campaign',
        Carbon::parse('2026-09-26 00:00:00'),
        100,
    );

    expect($memories->modelKeys())->toBe([$newer->getKey(), $matching->getKey()])
        ->and($memories->contains($older))->toBeFalse();
});

it('caps retrieval and keeps results isolated to the authorized Enterprise', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    AgentEpisodicMemory::factory()->forEnterprise($enterprise)->count(60)->create();
    AgentEpisodicMemory::factory()->forEnterprise($foreignEnterprise)->count(5)->create();

    $memories = app(AgentEpisodicMemoryService::class)->retrieve($user, $enterprise, null, null, null, 1000);

    expect($memories)->toHaveCount(50)
        ->and($memories->every(fn (AgentEpisodicMemory $memory): bool => $memory->enterprise_id === $enterprise->getKey()))->toBeTrue();
});

it('denies retrieval for an Enterprise outside the users organization', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();

    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    expect(fn () => app(AgentEpisodicMemoryService::class)->retrieve(
        $user,
        $foreignEnterprise,
    ))->toThrow(AuthorizationException::class);
});

it('denies recording a memory from an execution outside the users organization', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $foreignExecution = AgentExecution::factory()->forEnterprise($foreignEnterprise)->create();

    expect(fn () => app(AgentEpisodicMemoryService::class)->recordMeaningfulEvent(
        $user,
        $foreignExecution,
        'Objective',
        'Action',
        'Result',
        'Outcome',
    ))->toThrow(AuthorizationException::class);
});

it('keeps episodic memory separate from authoritative execution records', function () {
    $enterprise = Enterprise::factory()->create();
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
    ]);

    $memory = AgentEpisodicMemory::factory()->forExecution($execution)->create([
        'objective' => 'Objective summary',
        'action' => 'Action summary',
        'result' => 'Result summary',
        'outcome' => 'Outcome summary',
    ]);

    expect($memory->execution->is($execution))->toBeTrue()
        ->and($memory->toArray())->not->toHaveKey('prompt')
        ->and($memory->toArray())->not->toHaveKey('capability_requests');
});