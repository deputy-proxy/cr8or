<?php

use App\Models\AgentExecution;
use App\Models\Audience;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Services\MarketingStrategyWorkflowDefinition;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowVersionService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
    Queue::fake();
});

it('executes the canonical 18-stage marketing strategy workflow deterministically', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create([
        'id' => 4,
        'organization_id' => $organization->getKey(),
        'name' => 'Canonical Enterprise',
    ]);
    EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'industry' => 'Design',
        'business_model' => 'Services',
        'target_market' => 'Growth-stage companies',
        'geography' => 'Romania',
    ]);

    $definition = app(MarketingStrategyWorkflowDefinition::class);
    $workflow = $definition->createCanonical($enterprise);

    expect($workflow->stages()->count())->toBe(18)
        ->and($workflow->stages()->pluck('key')->all())->toHaveCount(18);

    app(WorkflowVersionService::class)->publish($workflow, $actor, 'canonical-marketing-strategy-v1');

    /** @var WorkflowEntryPointService $entryPoints */
    $entryPoints = app(WorkflowEntryPointService::class);
    $execution = $entryPoints->start(
        $actor,
        $workflow->refresh(),
        [
            'strategy_name' => 'Canonical Enterprise Marketing Strategy',
            'strategy_description' => 'Deterministic canonical strategy.',
        ],
        'canonical-marketing-strategy-execution',
    );

    $strategy = MarketingStrategy::query()
        ->where('enterprise_id', $enterprise->getKey())
        ->where('name', 'Canonical Enterprise Marketing Strategy')
        ->firstOrFail();

    expect($execution->status)->toBe(\App\Models\WorkflowExecution::STATUS_COMPLETED)
        ->and($workflow->refresh()->status)->toBe(Workflow::STATUS_SUCCEEDED)
        ->and($workflow->executions()->count())->toBe(1)
        ->and(Audience::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and($strategy->sections)->toBeArray()
        ->and($strategy->sections)->toHaveKeys([
            'enterprise_context',
            'business_market_context',
            'target_audiences',
            'positioning',
            'value_proposition',
            'competitive_landscape',
            'product_service_strategy',
            'marketing_objectives',
            'acquisition_channels',
            'content_strategy',
            'seo_strategy',
            'social_strategy',
            'conversion_strategy',
            'retention_strategy',
            'measurement_kpis',
            'roadmap_90_days',
            'completeness_validation',
        ])
        ->and(AgentExecution::query()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();
});

it('does not overwrite an existing strategy when canonical persistence is new-only', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    MarketingStrategy::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Existing Strategy',
        'description' => 'Original',
    ]);

    $definition = app(MarketingStrategyWorkflowDefinition::class);
    $workflow = $definition->createCanonical($enterprise);
    app(WorkflowVersionService::class)->publish($workflow, $actor, 'canonical-new-only');

    expect(fn () => app(WorkflowEntryPointService::class)->start(
        $actor,
        $workflow->refresh(),
        ['strategy_name' => 'Existing Strategy'],
        'canonical-new-only-execution',
    ))->toThrow('A marketing strategy named [Existing Strategy] already exists for this enterprise.');

    expect(MarketingStrategy::query()
        ->where('enterprise_id', $enterprise->getKey())
        ->where('name', 'Existing Strategy')
        ->count())->toBe(1);
});