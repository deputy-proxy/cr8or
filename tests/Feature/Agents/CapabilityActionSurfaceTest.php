<?php

use App\Agents\CeoAgent;
use App\Agents\FinanceAgent;
use App\Agents\MarketingAgent;
use App\Agents\OperationsAgent;
use App\Agents\ProductAgent;
use App\AI\Contracts\ModelProvider;
use App\AI\Providers\FakeModelProvider;
use App\Experts\BusinessAnalysisExpert;
use App\Experts\FinanceExpert;
use App\Experts\MarketingExpert;
use App\Experts\OperationsExpert;
use App\Experts\ProductExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\AnalyzeBusinessContextTool;
use App\Mcp\Tools\DelegateAgentTool;
use App\Mcp\Tools\GenerateFinancialReportTool;
use App\Mcp\Tools\PlanMarketingTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentDelegationService;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);

    app()->bind(ModelProvider::class, fn (): FakeModelProvider => FakeModelProvider::returning());
});

function capabilityActionMatrix(): array
{
    return [
        CeoAgent::class => ['agent.delegate' => DelegateAgentTool::class],
        MarketingAgent::class => [
            'marketing.plan' => PlanMarketingTool::class,
            'marketing.content.create' => 'create-content-item',
            'marketing.content.update' => 'update-content-item',
            'marketing.content.review' => 'submit-content-for-review',
            'marketing.content.publication-ready' => 'mark-content-publication-ready',
        ],
        FinanceAgent::class => ['finance.report.generate' => GenerateFinancialReportTool::class],
        ProductAgent::class => [
            'strategy.create' => 'create-strategy',
            'strategy.update' => 'update-strategy',
            'work.item.create' => 'create-work-item',
            'work.item.update' => 'update-work-item',
        ],
        OperationsAgent::class => [
            'work.item.create' => 'create-work-item',
            'work.item.update' => 'update-work-item',
        ],
    ];
}

it('maps every current Agent capability to an executable MCP action', function () {
    $agents = [
        CeoAgent::class,
        MarketingAgent::class,
        FinanceAgent::class,
        ProductAgent::class,
        OperationsAgent::class,
    ];

    $matrix = capabilityActionMatrix();

    foreach ($agents as $class) {
        foreach (app($class)->capabilities() as $capability) {
            expect($matrix[$class][$capability] ?? null)->not->toBeNull();
        }
    }
});

it('maps every current Expert capability to an executable governed expert action', function () {
    $experts = [
        BusinessAnalysisExpert::class => 'business.analysis',
        MarketingExpert::class => 'marketing.plan',
        FinanceExpert::class => 'finance.report.generate',
        OperationsExpert::class => 'work.item.create',
        ProductExpert::class => 'strategy.create',
    ];

    $descriptors = ExpertDescriptor::query()->get()->keyBy('runtime_class');

    foreach ($experts as $class => $capability) {
        expect($descriptors->get($class))->not->toBeNull()
            ->and(in_array($capability, app($class)->capabilities(), true))->toBeTrue();
    }
});

it('registers the new governed action tools for an organization member', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($actor, 'api')->tools()->assertRegistered([
        DelegateAgentTool::class,
        AnalyzeBusinessContextTool::class,
        PlanMarketingTool::class,
        GenerateFinancialReportTool::class,
    ]);
});

it('executes business analysis with only authorized enterprise context', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(AnalyzeBusinessContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'target_context' => ['question' => 'What changed?'],
        ])
        ->assertOk()
        ->assertSee(['business analysis', 'enterprise', 'strategy', 'work', 'financial']);
});

it('denies business analysis outside the actor organization', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(AnalyzeBusinessContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
        ])
        ->assertHasErrors();
});

it('executes marketing planning through the Marketing Expert', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(PlanMarketingTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'target_context' => ['objective' => 'launch'],
        ])
        ->assertOk()
        ->assertSee(['marketing planning', 'enterprise', 'strategy', 'knowledge']);
});

it('maps Finance execution to the governed financial reporting service', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $account = FinancialAccount::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'currency' => 'USD',
    ]);
    $period = FinancialPeriod::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);

    $report = app(\App\Services\FinancialReportingService::class)
        ->generate($actor, $enterprise, $period, $account);

    expect($report->currency)->toBe('USD');

    Cr8orServer::actingAs($actor, 'api')
        ->tool(GenerateFinancialReportTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'financial_period_id' => $period->getKey(),
            'financial_account_id' => $account->getKey(),
            'agent_assignment_id' => AgentAssignment::factory()->forEnterprise($enterprise)->create()->getKey(),
            'agent_execution_id' => AgentExecution::factory()->forAssignment(
                AgentAssignment::query()->latest('id')->firstOrFail(),
            )->create(['actor_id' => $actor->getKey()])->getKey(),
        ])
        ->assertHasErrors();
});

it('rejects an Agent-backed analysis call when the declared capability is missing', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'actor_id' => $actor->getKey(),
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(AnalyzeBusinessContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => $execution->getKey(),
        ])
        ->assertHasErrors();
});

it('delegates through AgentDelegationService and preserves idempotency', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $source = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => AgentDescriptor::query()->where('slug', 'ceo')->value('id'),
    ]);
    $target = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => AgentDescriptor::query()->where('slug', 'finance')->value('id'),
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $source->getKey(),
        'capability' => AgentDelegationService::DELEGATION_CAPABILITY,
    ]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'finance.report.generate',
    ]);

    $payload = [
        'source_agent_assignment_id' => $source->getKey(),
        'target_agent_slug' => 'finance',
        'capability' => 'finance.report.generate',
        'prompt' => 'Create the approved work.',
        'target_context' => ['enterprise_id' => $enterprise->getKey()],
        'idempotency_key' => 'mcp-delegation-123',
    ];

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(DelegateAgentTool::class, $payload)->assertOk();
    $server->tool(DelegateAgentTool::class, $payload)->assertOk();

    expect(\App\Models\AgentDelegation::query()
        ->where('idempotency_key', 'mcp-delegation-123')
        ->count())->toBe(1);
});