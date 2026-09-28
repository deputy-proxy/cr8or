<?php

use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Operations\ArchiveMemory;
use App\Operations\CreateMemory;
use App\Operations\GetMemory;
use App\Operations\ListMemory;
use App\Operations\RetrieveMemory;
use App\Operations\UpdateMemory;
use App\Services\AgentMemoryPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

function memoryResourceActor(): array
{
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'status' => AgentExecution::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);

    return [$user, $enterprise, $execution, AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id)];
}

it('round trips episodic and semantic Memory with provenance', function (): void {
    [$user, $enterprise, $execution, $agent] = memoryResourceActor();

    $episodic = app(CreateMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => AgentMemoryPolicy::TYPE_EPISODIC,
        'source_execution_id' => $execution->getKey(),
        'topic' => 'campaigns',
        'objective' => 'Improve qualified leads.',
        'action' => 'Retained the campaign direction.',
        'result' => 'Qualified leads increased.',
        'outcome' => 'The direction remains useful.',
    ]);

    $semantic = app(CreateMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => AgentMemoryPolicy::TYPE_SEMANTIC,
        'source_execution_id' => $execution->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
        'statement' => 'The enterprise prefers concise campaign copy.',
        'confidence' => 0.92,
    ]);

    expect($episodic['type'])->toBe('episodic')
        ->and($episodic['provenance']['source_id'])->toBe($execution->getKey())
        ->and($semantic['type'])->toBe('semantic')
        ->and($semantic['confidence'])->toBe(0.92)
        ->and($semantic['provenance']['source_id'])->toBe($execution->getKey())
        ->and(AgentEpisodicMemory::query()->count())->toBe(1)
        ->and(AgentSemanticMemory::query()->count())->toBe(1);

    $listed = app(ListMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 50,
    ]);

    expect($listed['items'])->toHaveCount(2);

    expect(app(GetMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'memory_id' => $semantic['id'],
    ]))->toMatchArray([
        'id' => $semantic['id'],
        'statement' => 'The enterprise prefers concise campaign copy.',
    ]);
});

it('updates semantic Memory with version history and archives it without changing provenance', function (): void {
    [$user, $enterprise, $execution, $agent] = memoryResourceActor();

    $memory = app(CreateMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'source_execution_id' => $execution->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
        'statement' => 'Short copy is preferred.',
        'confidence' => 0.8,
    ]);

    $updated = app(UpdateMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'semantic',
        'memory_id' => $memory['id'],
        'source_execution_id' => $execution->getKey(),
        'statement' => 'Short copy with direct CTAs is preferred.',
        'confidence' => 0.95,
    ]);

    $archived = app(ArchiveMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'memory_id' => $memory['id'],
    ]);

    $stored = AgentSemanticMemory::query()->findOrFail($memory['id']);

    expect($updated['statement'])->toBe('Short copy with direct CTAs is preferred.')
        ->and($archived['status'])->toBe(AgentSemanticMemory::STATUS_ARCHIVED)
        ->and($stored->versions()->pluck('change_type')->all())->toBe(['created', 'updated', 'updated'])
        ->and($stored->provenance['source_id'])->toBe($execution->getKey());
});

it('treats episodic Memory as immutable and bounds retrieval to the Enterprise and Agent', function (): void {
    [$user, $enterprise, $execution, $agent] = memoryResourceActor();

    app(CreateMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'episodic',
        'source_execution_id' => $execution->getKey(),
        'objective' => 'Objective',
        'action' => 'Action',
        'result' => 'Result',
        'outcome' => 'Outcome',
    ]);

    expect(fn () => app(UpdateMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'episodic',
        'memory_id' => 1,
        'source_execution_id' => $execution->getKey(),
        'statement' => 'not allowed',
        'confidence' => 0.5,
    ]))->toThrow(InvalidArgumentException::class);

    AgentSemanticMemory::factory()->forAgent($agent, $enterprise)->count(60)->create();
    $foreign = Enterprise::factory()->create();
    AgentSemanticMemory::factory()->forEnterprise($foreign)->count(5)->create();

    $retrieved = app(RetrieveMemory::class)->execute($user, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
        'semantic_limit' => 50,
    ]);

    expect($retrieved['semantic'])->toHaveCount(50)
        ->and(collect($retrieved['semantic'])->every(fn (array $item): bool => $item['enterprise_id'] === $enterprise->getKey()))->toBeTrue();

    expect(fn () => app(RetrieveMemory::class)->execute($user, [
        'enterprise_id' => $foreign->getKey(),
        'agent_descriptor_id' => $agent->getKey(),
    ]))->toThrow(AuthorizationException::class);
});