<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Strategy;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use App\Services\CanonicalWorkflowProvisioner;
use App\Services\StrategyCreationWorkflowDefinition;
use App\Services\WorkflowEntryPointService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed([\Database\Seeders\ExpertDescriptorSeeder::class]);
    Queue::fake();
});

it('provisions the canonical create strategy workflow once', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $provisioner = app(CanonicalWorkflowProvisioner::class);
    $first = $provisioner->provisionStrategyCreation($enterprise, $actor);
    $second = $provisioner->provisionStrategyCreation($enterprise, $actor);

    expect($first->is($second))->toBeTrue()
        ->and($first->canonical_key)->toBe(StrategyCreationWorkflowDefinition::CANONICAL_KEY)
        ->and($first->publishedVersion->status)->toBe(WorkflowVersion::STATUS_PUBLISHED)
        ->and($first->stages)->toHaveCount(1)
        ->and($first->stages->first()->key)->toBe('create_strategy')
        ->and($first->stages->first()->expert_slugs)->toBe(['strategy'])
        ->and($first->stages->first()->capability_slugs)->toBe(['strategy.create'])
        ->and($first->versions()->count())->toBe(1);
});

it('executes create strategy deterministically through the workflow without AgentExecution', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $objective = Objective::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Increase qualified demand',
    ]);

    $workflow = app(CanonicalWorkflowProvisioner::class)->provisionStrategyCreation($enterprise, $actor);

    $execution = app(WorkflowEntryPointService::class)->start(
        $actor,
        $workflow,
        [
            'objective_id' => $objective->getKey(),
            'name' => 'Qualified Demand Strategy',
            'description' => 'A deterministic strategy created through the Workflow boundary.',
        ],
        'create-strategy-workflow-test',
    );

    $strategy = Strategy::query()->where('name', 'Qualified Demand Strategy')->first();

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->outputs)->toHaveKey('create_strategy')
        ->and($strategy)->not->toBeNull()
        ->and($strategy->objective_id)->toBe($objective->getKey())
        ->and($strategy->description)->toBe('A deterministic strategy created through the Workflow boundary.')
        ->and(\App\Models\AgentExecution::query()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();
});