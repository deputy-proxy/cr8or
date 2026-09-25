<?php

use App\Agents\OperationsAgent;
use App\Enums\MembershipRole;
use App\Experts\OperationsExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\GetAgentDescriptorTool;
use App\Mcp\Tools\GetApprovalRequestTool;
use App\Mcp\Tools\GetAudienceTool;
use App\Mcp\Tools\GetCampaignTool;
use App\Mcp\Tools\GetCapabilityTool;
use App\Mcp\Tools\GetChannelTool;
use App\Mcp\Tools\GetContentItemTool;
use App\Mcp\Tools\GetContentSeriesTool;
use App\Mcp\Tools\GetEnterpriseTool;
use App\Mcp\Tools\GetExecutionTool;
use App\Mcp\Tools\GetExpertDescriptorTool;
use App\Mcp\Tools\GetObjectiveTool;
use App\Mcp\Tools\GetStrategyTool;
use App\Mcp\Tools\GetWorkItemTool;
use App\Mcp\Tools\ListAgentDescriptorTool;
use App\Mcp\Tools\ListApprovalRequestTool;
use App\Mcp\Tools\ListAudienceTool;
use App\Mcp\Tools\ListCampaignTool;
use App\Mcp\Tools\ListCapabilitiesTool;
use App\Mcp\Tools\ListChannelTool;
use App\Mcp\Tools\ListContentItemTool;
use App\Mcp\Tools\ListContentSeriesTool;
use App\Mcp\Tools\ListEnterpriseTool;
use App\Mcp\Tools\ListExecutionTool;
use App\Mcp\Tools\ListExpertDescriptorTool;
use App\Mcp\Tools\ListObjectiveTool;
use App\Mcp\Tools\ListStrategyTool;
use App\Mcp\Tools\ListWorkItemTool;
use App\Models\AgentDescriptor;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Strategy;
use App\Models\User;

function discoveryMember(User $user, Organization $organization): void
{
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
}

it('registers every foundational discovery tool', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    discoveryMember($user, $organization);

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        ListEnterpriseTool::class, GetEnterpriseTool::class,
        ListObjectiveTool::class, GetObjectiveTool::class,
        ListStrategyTool::class, GetStrategyTool::class,
        ListWorkItemTool::class, GetWorkItemTool::class,
        ListAgentDescriptorTool::class, GetAgentDescriptorTool::class,
        ListExpertDescriptorTool::class, GetExpertDescriptorTool::class,
        ListCapabilitiesTool::class, GetCapabilityTool::class,
        ListCampaignTool::class, GetCampaignTool::class,
        ListContentSeriesTool::class, GetContentSeriesTool::class,
        ListContentItemTool::class, GetContentItemTool::class,
        ListAudienceTool::class, GetAudienceTool::class,
        ListChannelTool::class, GetChannelTool::class,
        ListExecutionTool::class, GetExecutionTool::class,
        ListApprovalRequestTool::class, GetApprovalRequestTool::class,
    ]);
});

it('lists and gets enterprise-scoped strategy resources with filters and pagination', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    discoveryMember($user, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    Strategy::factory()->create(['objective_id' => $objective->getKey(), 'name' => 'Marketing Strategy']);
    Strategy::factory()->create(['objective_id' => $objective->getKey(), 'name' => 'Operations Strategy']);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $foreignObjective = Objective::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    Strategy::factory()->create(['objective_id' => $foreignObjective->getKey(), 'name' => 'Foreign Strategy']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListStrategyTool::class, [
            'search' => 'Marketing',
            'objective_id' => $objective->getKey(),
            'per_page' => 1,
            'page' => 1,
        ])
        ->assertOk()
        ->assertSee(['Marketing Strategy', '"total":1', '"last_page":1']);

    $strategy = Strategy::query()->where('name', 'Marketing Strategy')->firstOrFail();
    Cr8orServer::actingAs($user, 'api')
        ->tool(GetStrategyTool::class, ['id' => $strategy->getKey()])
        ->assertOk()
        ->assertSee(['Marketing Strategy', (string) $objective->getKey()]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetStrategyTool::class, ['id' => Strategy::query()->where('name', 'Foreign Strategy')->value('id')])
        ->assertHasErrors();
});

it('lists content resources through enterprise relationships and blocks cross-organization data', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    discoveryMember($user, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $marketingStrategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->getKey(), 'marketing_strategy_id' => $marketingStrategy->getKey()]);
    $series = ContentSeries::factory()->create(['campaign_id' => $campaign->getKey()]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    ContentItem::factory()->forSeries($series)->create(['title' => 'Discoverable content']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListContentItemTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee('Discoverable content');

    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $foreignMarketingStrategy = MarketingStrategy::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $foreignCampaign = Campaign::factory()->create(['enterprise_id' => $foreignEnterprise->getKey(), 'marketing_strategy_id' => $foreignMarketingStrategy->getKey()]);
    $foreignItem = ContentItem::factory()->create([
        'enterprise_id' => $foreignEnterprise->getKey(),
        'campaign_id' => $foreignCampaign->getKey(),
        'title' => 'Foreign content',
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetContentItemTool::class, ['id' => $foreignItem->getKey()])
        ->assertHasErrors();
});

it('requires policy authorization for descriptor discovery', function () {
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    discoveryMember($member, $organization);

    Cr8orServer::actingAs($member, 'api')
        ->tool(ListAgentDescriptorTool::class, [])
        ->assertHasErrors();

    Cr8orServer::actingAs($member, 'api')
        ->tool(ListExpertDescriptorTool::class, [])
        ->assertHasErrors();
});

it('discovers runtime capabilities without introducing a persistent capability model', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    discoveryMember($user, $organization);
    AgentDescriptor::factory()->create(['slug' => 'operations', 'runtime_class' => OperationsAgent::class, 'enabled' => true]);
    ExpertDescriptor::factory()->create(['slug' => 'operations', 'runtime_class' => OperationsExpert::class, 'enabled' => true]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListCapabilitiesTool::class, ['search' => 'work.'])
        ->assertOk()
        ->assertSee(['work.create', 'work.update']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetCapabilityTool::class, ['id' => 'work.create'])
        ->assertOk()
        ->assertSee(['work.create']);
});