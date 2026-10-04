<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEntryPointService;
use Illuminate\Auth\Access\AuthorizationException;

it('creates a workflow with its canonical key through the governed entry point', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $workflow = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Marketing Strategy Workflow',
        'canonical_key' => 'marketing.strategy.create',
        'purpose' => 'Canonical deterministic marketing strategy workflow.',
        'stages' => [
            [
                'key' => 'persist_strategy',
                'sequence' => 1,
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.create'],
                'output_contract' => [],
            ],
        ],
    ]);

    expect($workflow)->toBeInstanceOf(Workflow::class)
        ->and($workflow->canonical_key)->toBe('marketing.strategy.create')
        ->and($workflow->enterprise_id)->toBe($enterprise->getKey())
        ->and($workflow->stages)->toHaveCount(1)
        ->and($workflow->stages->first()->expert_slugs)->toBe(['marketing'])
        ->and($workflow->stages->first()->capability_slugs)->toBe(['marketing.strategy.create'])
        ->and($workflow->stages->first()->capability_input_contract)->not->toBeEmpty()
        ->and($workflow->stages->first()->capability_output_contract)->not->toBeEmpty()
        ->and($workflow->stages->first()->output_contract)->not->toBeEmpty();
});

it('requires enterprise-scoped Workflow creation authorization', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    expect(fn () => app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Unauthorized Workflow',
        'stages' => [
            [
                'key' => 'stage',
                'sequence' => 1,
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.create'],
            ],
        ],
    ]))->toThrow(AuthorizationException::class);
});
it('reconciles a workflow stage schema missing canonical capability contract snapshot columns', function (): void {
    $schema = \Illuminate\Support\Facades\Schema::connection('sqlite');
    $schema->table('workflow_stages', function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->dropColumn(['capability_input_contract', 'capability_output_contract']);
    });

    expect($schema->hasColumns('workflow_stages', [
        'capability_input_contract',
        'capability_output_contract',
    ]))->toBeFalse();

    $migration = require base_path('database/migrations/2026_10_04_130000_reconcile_workflow_stage_capability_contract_columns.php');
    $migration->up();

    expect($schema->hasColumns('workflow_stages', [
        'capability_input_contract',
        'capability_output_contract',
    ]))->toBeTrue();
});