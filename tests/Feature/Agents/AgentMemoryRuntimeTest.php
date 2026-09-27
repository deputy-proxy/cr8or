<?php

use App\AI\Data\ModelResult;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentMemoryRuntimeService;

function memoryRuntimeResult(array $memory): ModelResult
{
    return new ModelResult(
        text: 'Completed.',
        structured: [
            'answer' => 'Completed.',
            'decision_title' => 'Memory test decision',
            'decision_summary' => 'Structured result used for memory consolidation.',
            'decision_rationale' => 'Test fixture.',
            'capability_requests' => [],
            'delegation_requests' => [],
            'termination' => 'completed',
            'termination_reason' => 'completed',
            'next_step' => '',
            'memory' => $memory,
        ],
        provider: 'fake',
        model: 'fake-model',
        invocationId: 'memory-test',
    );
}

it('consolidates explicit episodic and high-confidence semantic candidates', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    app(AgentMemoryRuntimeService::class)->consolidate(
        $user,
        $execution,
        memoryRuntimeResult([
            'episodic' => [[
                'persist' => true,
                'topic' => 'campaigns',
                'objective' => 'Improve qualified leads.',
                'action' => 'Retained the campaign direction.',
                'result' => 'Qualified leads increased.',
                'outcome' => 'The direction remains useful.',
            ]],
            'semantic' => [[
                'persist' => true,
                'statement' => 'The enterprise prefers concise campaign copy.',
                'confidence' => 0.9,
            ]],
        ]),
        null,
    );

    expect(AgentEpisodicMemory::query()->count())->toBe(1)
        ->and(AgentSemanticMemory::query()->count())->toBe(1)
        ->and(AgentEpisodicMemory::query()->first()->provenance['source_id'])->toBe($execution->getKey())
        ->and(AgentSemanticMemory::query()->first()->provenance['source_id'])->toBe($execution->getKey());
});

it('rejects low-confidence, unmarked and oversized candidates without creating memory', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    app(AgentMemoryRuntimeService::class)->consolidate(
        $user,
        $execution,
        memoryRuntimeResult([
            'episodic' => [[
                'persist' => false,
                'objective' => 'Transient noise.',
                'action' => 'Ignored.',
                'result' => 'Ignored.',
                'outcome' => 'Ignored.',
            ], [
                'persist' => true,
                'objective' => str_repeat('x', 2001),
                'action' => 'Ignored.',
                'result' => 'Ignored.',
                'outcome' => 'Ignored.',
            ]],
            'semantic' => [[
                'persist' => true,
                'statement' => 'Low confidence observation.',
                'confidence' => 0.74,
            ]],
        ]),
        null,
    );

    expect(AgentEpisodicMemory::query()->count())->toBe(0)
        ->and(AgentSemanticMemory::query()->count())->toBe(0);
});

it('records meaningful failure candidates as episodic memory but never semantic memory', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_FAILED,
        'failure_reason' => 'Provider timeout',
        'completed_at' => now(),
    ]);

    app(AgentMemoryRuntimeService::class)->consolidate(
        $user,
        $execution,
        memoryRuntimeResult([
            'episodic' => [[
                'persist' => true,
                'topic' => 'provider reliability',
                'objective' => 'Complete the requested execution.',
                'action' => 'Requested model execution.',
                'result' => 'Provider timed out.',
                'outcome' => 'Retry should use the governed failure path.',
            ]],
            'semantic' => [[
                'persist' => true,
                'statement' => 'The provider is unreliable.',
                'confidence' => 0.99,
            ]],
        ]),
        null,
    );

    expect(AgentEpisodicMemory::query()->count())->toBe(1)
        ->and(AgentSemanticMemory::query()->count())->toBe(0);
});

it('deduplicates active semantic memories and explicitly supersedes scoped memories', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    $agent = $execution->agentDescriptor;
    $existing = AgentSemanticMemory::factory()->forAgent($agent, $enterprise)->create([
        'statement' => 'The enterprise prefers concise copy.',
        'status' => AgentSemanticMemory::STATUS_ACTIVE,
    ]);

    app(AgentMemoryRuntimeService::class)->consolidate(
        $user,
        $execution,
        memoryRuntimeResult([
            'semantic' => [[
                'persist' => true,
                'statement' => 'The enterprise prefers concise copy.',
                'confidence' => 0.9,
            ], [
                'persist' => true,
                'statement' => 'The enterprise now prefers concise copy with direct CTAs.',
                'confidence' => 0.92,
                'supersedes_memory_id' => $existing->getKey(),
            ]],
        ]),
        null,
    );

    $existing->refresh();

    expect(AgentSemanticMemory::query()->where('status', AgentSemanticMemory::STATUS_ACTIVE)->count())->toBe(1)
        ->and($existing->status)->toBe(AgentSemanticMemory::STATUS_SUPERSEDED)
        ->and(AgentSemanticMemory::query()->where('statement', 'The enterprise now prefers concise copy with direct CTAs.')->exists())->toBeTrue();
});