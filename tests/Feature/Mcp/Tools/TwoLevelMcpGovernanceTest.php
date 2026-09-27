<?php

use App\Capabilities\CapabilityRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ArchiveAudienceTool;
use App\Mcp\Tools\ArchiveChannelTool;
use App\Mcp\Tools\ArchiveMarketingStrategyTool;
use App\Mcp\Tools\ConnectSocialAccountTool;
use App\Mcp\Tools\CreateAudienceTool;
use App\Mcp\Tools\CreateCampaignTool;
use App\Mcp\Tools\CreateChannelTool;
use App\Mcp\Tools\CreateContentSeriesTool;
use App\Mcp\Tools\CreateEnterpriseContextTool;
use App\Mcp\Tools\CreateEnterpriseTool;
use App\Mcp\Tools\CreateObjectiveTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\DisconnectSocialAccountTool;
use App\Mcp\Tools\DomainMutationTool;
use App\Mcp\Tools\RequestApprovalTool;
use App\Mcp\Tools\TransitionCampaignTool;
use App\Mcp\Tools\TransitionContentSeriesTool;
use App\Mcp\Tools\UpdateAudienceTool;
use App\Mcp\Tools\UpdateCampaignTool;
use App\Mcp\Tools\UpdateChannelTool;
use App\Mcp\Tools\UpdateContentSeriesTool;
use App\Mcp\Tools\UpdateObjectiveTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateSocialAccountTool;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;

function governanceAgentContext(User $actor, Enterprise $enterprise): array
{
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create([
        'actor_id' => $actor->getKey(),
        'actor_name' => $actor->name,
    ]);

    return [$assignment, $execution];
}

it('maps every state-changing MCP tool to one explicit capability and one operation', function () {
    $tools = [
        CreateEnterpriseTool::class,
        CreateEnterpriseContextTool::class,
        CreateObjectiveTool::class,
        UpdateObjectiveTool::class,
        CreateCampaignTool::class,
        UpdateCampaignTool::class,
        TransitionCampaignTool::class,
        CreateContentSeriesTool::class,
        UpdateContentSeriesTool::class,
        TransitionContentSeriesTool::class,
        CreateAudienceTool::class,
        UpdateAudienceTool::class,
        ArchiveAudienceTool::class,
        CreateChannelTool::class,
        UpdateChannelTool::class,
        ArchiveChannelTool::class,
        ArchiveMarketingStrategyTool::class,
        ConnectSocialAccountTool::class,
        UpdateSocialAccountTool::class,
        DisconnectSocialAccountTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,
        RequestApprovalTool::class,
    ];

    $registry = app(CapabilityRegistry::class);
    $definitions = array_map(fn (string $tool) => $registry->forTool($tool), $tools);

    expect(count($definitions))->toBe(count($tools))
        ->and(array_unique(array_map(fn ($definition) => $definition->key, $definitions)))->toHaveCount(count($tools))
        ->and(array_unique(array_map(fn ($definition) => $definition->operation, $definitions)))->toHaveCount(count($tools));

    foreach ($definitions as $definition) {
        expect($registry->operationForTool($definition->toolClass))->toBeInstanceOf(App\Contracts\Operation::class);
    }
});

it('does not expose generic capability or operation fallbacks on DomainMutationTool', function () {
    $reflection = new ReflectionClass(DomainMutationTool::class);

    expect($reflection->hasMethod('capability'))->toBeFalse()
        ->and($reflection->hasMethod('operation'))->toBeFalse();
});

it('does not allow the generic mutation capability to authorize an Agent-backed domain mutation', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->id,
        'organization_id' => $organization->id,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    [$assignment, $execution] = governanceAgentContext($actor, $enterprise);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->id,
        'capability' => 'mcp.domain.mutation',
    ]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['marketing_strategy_id' => $strategy->id]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateCampaignTool::class, [
            'campaign_id' => $campaign->id,
            'name' => 'Should remain unchanged',
            'agent_assignment_id' => $assignment->id,
            'agent_execution_id' => $execution->id,
        ])
        ->assertHasErrors();

    expect($campaign->refresh()->name)->not->toBe('Should remain unchanged');
});

it('authorizes a domain mutation only with its explicit Agent capability', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->id,
        'organization_id' => $organization->id,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    [$assignment, $execution] = governanceAgentContext($actor, $enterprise);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['marketing_strategy_id' => $strategy->id]);

    AgentPermission::factory()->create([
        'agent_assignment_id' => $assignment->id,
        'capability' => 'marketing.campaign.update',
    ]);

    expect(app(AgentCapabilityAuthorizer::class)->allows(
        $assignment,
        'marketing.campaign.update',
        $organization,
        $enterprise,
        $actor,
        null,
        $execution,
        ['campaign_id' => $campaign->id],
    ))->toBeTrue();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateCampaignTool::class, [
            'campaign_id' => $campaign->id,
            'name' => 'Explicitly authorized',
            'agent_assignment_id' => $assignment->id,
            'agent_execution_id' => $execution->id,
        ])
        ->assertOk();

    expect($campaign->refresh()->name)->toBe('Explicitly authorized');
});

it('preserves human domain policy authorization for explicit domain capabilities', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->id,
        'organization_id' => $organization->id,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['marketing_strategy_id' => $strategy->id]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateCampaignTool::class, [
            'campaign_id' => $campaign->id,
            'name' => 'Human authorized',
        ])
        ->assertOk();

    expect($campaign->refresh()->name)->toBe('Human authorized');
});

it('rejects an Agent capability that is not registered for the MCP tool', function () {
    $registry = app(CapabilityRegistry::class);

    expect(fn () => $registry->resolve('mcp.domain.mutation'))->toThrow(InvalidArgumentException::class);
    expect($registry->forTool(UpdateCampaignTool::class)->key)->toBe('marketing.campaign.update');
});