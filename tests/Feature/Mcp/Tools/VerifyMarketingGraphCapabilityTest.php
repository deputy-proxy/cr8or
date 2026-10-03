<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\VerifyMarketingGraphTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Asset;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Script;
use App\Models\User;

it('verifies a marketing graph through the capability without Agent context', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $descriptor = AgentDescriptor::factory()->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey()]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey(), 'actor_name' => $actor->name]);

    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $audience = Audience::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'marketing_strategy_id' => $strategy->getKey(),
    ]);
    $series = ContentSeries::factory()->create(['campaign_id' => $campaign->getKey()]);
    $item = ContentItem::factory()->forSeries($series)->create(['audience_id' => $audience->getKey()]);
    $script = Script::factory()->create(['content_item_id' => $item->getKey(), 'agent_assignment_id' => $assignment->getKey(), 'agent_execution_id' => $execution->getKey()]);
    $asset = Asset::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'script_id' => $script->getKey(),
        'content_item_id' => $item->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'status' => Asset::STATUS_PENDING,
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(VerifyMarketingGraphTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'marketing_strategy_id' => $strategy->getKey(),
            'audience_ids' => [$audience->getKey()],
            'campaign_ids' => [$campaign->getKey()],
            'content_series_ids' => [$series->getKey()],
            'content_item_ids' => [$item->getKey()],
            'script_ids' => [$script->getKey()],
            'asset_ids' => [$asset->getKey()],
        ])
        ->assertOk();
});