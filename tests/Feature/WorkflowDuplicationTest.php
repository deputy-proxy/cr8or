<?php

use AppModels\Enterprise;
use AppModels\Membership;
use AppModels\User;
use App\Models\Workflow;
use App\Operations\DuplicateWorkflow;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowVersionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('duplicates a workflow definition without published versions or executions', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $source = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Marketing Strategy Workflow',
        'canonical_key' => 'marketing.strategy.create',
        'purpose' => 'Canonical deterministic marketing strategy workflow.',
        'execution_policy' => ['mode' => 'interactive', 'requires_model_provider' => false],
        'completion_criteria' => ['required_stage_keys' => ['strategy']],
        'stages' => [[
            'key' => 'strategy',
            'name' => 'Create strategy',
            'sequence' => 1,
            'instruction' => 'Create the strategy.',
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.strategy.create'],
            'input_contract' => [
                'required' => ['enterprise_id', 'name'],
                'defaults' => [],
                'mappings' => [],
            ],
        ]],
    ]);

    app(WorkflowVersionService::class)->publish($source, $actor, 'test:workflow-duplicate-source');

    $duplicate = app(DuplicateWorkflow::class)->execute($actor, [
        'workflow' => $source,
    ]);

    expect($duplicate->id)->not->toBe($source->id)
        ->and($duplicate->enterprise_id)->toBe($source->enterprise_id)
        ->and($duplicate->name)->toBe('Marketing Strategy Workflow (Copy)')
        ->and($duplicate->canonical_key)->toBe('marketing.strategy.create.copy')
        ->and($duplicate->status)->toBe(Workflow::STATUS_PENDING)
        ->and($duplicate->version)->toBe(1)
        ->and($duplicate->published_version_id)->toBeNull()
        ->and($duplicate->execution_policy)->toBe($source->execution_policy)
        ->and($duplicate->completion_criteria)->toBe($source->completion_criteria)
        ->and($duplicate->stages)->toHaveCount(1)
        ->and($duplicate->stages->first()->key)->toBe('strategy')
        ->and($duplicate->stages->first()->instruction)->toBe('Create the strategy.')
        ->and($duplicate->stages->first()->input_contract)->toBe($source->stages->first()->input_contract)
        ->and($duplicate->versions()->count())->toBe(0)
        ->and($duplicate->executions()->count())->toBe(0);
});

it('increments duplicate names and canonical keys', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $source = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Marketing Workflow',
        'canonical_key' => 'marketing.workflow',
        'stages' => [[
            'key' => 'strategy',
            'sequence' => 1,
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.strategy.create'],
        ]],
    ]);

    $first = app(DuplicateWorkflow::class)->execute($actor, ['workflow' => $source]);
    $second = app(DuplicateWorkflow::class)->execute($actor, ['workflow' => $source]);

    expect($first->name)->toBe('Marketing Workflow (Copy)')
        ->and($first->canonical_key)->toBe('marketing.workflow.copy')
        ->and($second->name)->toBe('Marketing Workflow (Copy 2)')
        ->and($second->canonical_key)->toBe('marketing.workflow.copy-2');
});

it('requires enterprise-scoped workflow creation authorization to duplicate', function (): void {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $source = app(WorkflowEntryPointService::class)->create($owner, $enterprise, [
        'name' => 'Authorized Workflow',
        'stages' => [[
            'key' => 'strategy',
            'sequence' => 1,
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.strategy.create'],
        ]],
    ]);

    expect(fn () => app(DuplicateWorkflow::class)->execute($actor, [
        'workflow' => $source,
    ]))->toThrow(AuthorizationException::class);
});
