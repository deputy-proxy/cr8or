<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ListWorkflowsTool;
use App\Mcp\Tools\StartWorkflowTool;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Services\CanonicalWorkflowProvisioner;
use App\Services\MarketingStrategyWorkflowDefinition;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed([\Database\Seeders\ExpertDescriptorSeeder::class]);
    Queue::fake();
});

it('executes the canonical 18-stage marketing strategy through the MCP Workflow interface', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'slug' => 'valid.guide',
        'name' => 'valid.guide',
    ]);
    EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'industry' => 'Education and learning',
        'business_model' => 'Digital services',
        'target_market' => 'People evaluating courses and guides',
        'geography' => 'Romania',
    ]);
    $existing = MarketingStrategy::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Existing valid.guide strategy',
        'description' => 'Must remain untouched.',
    ]);

    $workflow = app(CanonicalWorkflowProvisioner::class)->provisionMarketingStrategy($enterprise, $actor);
    $beforeExisting = $existing->fresh()->toArray();

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(ListWorkflowsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'canonical_key' => MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE,
    ])
        ->assertOk()
        ->assertSee($workflow->getKey())
        ->assertSee(MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE)
        ->assertSee($workflow->published_version_id);

    $server->tool(StartWorkflowTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'workflow_id' => $workflow->getKey(),
        'input' => [
            'strategy_name' => 'valid.guide deterministic marketing strategy',
            'strategy_description' => 'A completely new deterministic strategy.',
        ],
        'idempotency_key' => 'valid-guide-marketing-strategy-20261002',
        'correlation_id' => 'valid-guide-marketing-strategy-integration',
    ])->assertOk();

    $execution = WorkflowExecution::query()
        ->where('workflow_id', $workflow->getKey())
        ->where('idempotency_key', 'valid-guide-marketing-strategy-20261002')
        ->firstOrFail();
    $strategy = MarketingStrategy::query()
        ->where('enterprise_id', $enterprise->getKey())
        ->where('name', 'valid.guide deterministic marketing strategy')
        ->firstOrFail();

    expect($workflow->stages()->count())->toBe(18)
        ->and($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->workflow_version_id)->toBe($workflow->published_version_id)
        ->and(array_keys($execution->outputs))->toHaveCount(18)
        ->and($strategy->getKey())->not->toBe($existing->getKey())
        ->and($strategy->sections)->toHaveCount(17)
        ->and($existing->fresh()->toArray())->toBe($beforeExisting)
        ->and(WorkflowExecution::query()->where('workflow_id', $workflow->getKey())->count())->toBe(1)
        ->and(AgentExecution::query()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();

    $server->tool(StartWorkflowTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'workflow_id' => $workflow->getKey(),
        'input' => [
            'strategy_name' => 'valid.guide deterministic marketing strategy',
        ],
        'idempotency_key' => 'valid-guide-marketing-strategy-20261002',
    ])->assertOk();

    expect(WorkflowExecution::query()->where('workflow_id', $workflow->getKey())->count())->toBe(1)
        ->and(MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(2);
});
