<?php

use App\Enums\MembershipRole;
use App\Mcp\Resources\EnterpriseContextResource;
use App\Mcp\Resources\KnowledgeContextResource;
use App\Mcp\Resources\StrategyContextResource;
use App\Mcp\Resources\WorkContextResource;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeItem;
use App\Models\Membership;
use App\Models\Objective;
use App\Models\Organization;
use App\Models\Strategy;
use App\Models\User;
use App\Models\WorkItem;
use Tests\Support\Mcp\AuthenticatedTestServer;

function mcpMember(User $user, Organization $organization): void
{
    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => MembershipRole::Member,
    ]);
}

function mcpEnterprise(Organization $organization, string $name = 'Test Enterprise'): Enterprise
{
    return Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'name' => $name,
    ]);
}

it('returns authorized enterprise context', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpMember($user, $organization);
    $enterprise = mcpEnterprise($organization);
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    AuthenticatedTestServer::actingAs($user, 'api')
        ->resource(EnterpriseContextResource::class, ['enterprise' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee([$enterprise->name, $enterprise->slug]);
});

it('returns authorized strategy, knowledge and work context', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpMember($user, $organization);
    $enterprise = mcpEnterprise($organization);

    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->getKey()]);
    $knowledgeContext = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $knowledgeItem = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_context_id' => $knowledgeContext->getKey(),
    ]);
    $workItem = WorkItem::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    $server = AuthenticatedTestServer::actingAs($user, 'api');

    $server->resource(StrategyContextResource::class, ['enterprise' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee([$strategy->name, $objective->name]);

    $server->resource(KnowledgeContextResource::class, ['enterprise' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee([$knowledgeContext->name, $knowledgeItem->title]);

    $server->resource(WorkContextResource::class, ['enterprise' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee($workItem->name);
});

it('denies cross-organization enterprise context access', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    mcpMember($user, $organization);
    $foreignEnterprise = mcpEnterprise($foreignOrganization, 'Foreign Enterprise');
    EnterpriseContext::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);

    AuthenticatedTestServer::actingAs($user, 'api')
        ->resource(EnterpriseContextResource::class, ['enterprise' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
});

it('denies cross-organization access for every context resource', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    mcpMember($user, $organization);
    $foreignEnterprise = mcpEnterprise($foreignOrganization);

    $server = AuthenticatedTestServer::actingAs($user, 'api');

    $server->resource(StrategyContextResource::class, ['enterprise' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
    $server->resource(KnowledgeContextResource::class, ['enterprise' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
    $server->resource(WorkContextResource::class, ['enterprise' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
});

it('rejects invalid enterprise identifiers', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpMember($user, $organization);

    AuthenticatedTestServer::actingAs($user, 'api')
        ->resource(EnterpriseContextResource::class, ['enterprise' => 'not-an-id'])
        ->assertHasErrors();
});

it('does not expose persistence metadata in resource output', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    mcpMember($user, $organization);
    $enterprise = mcpEnterprise($organization);
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    AuthenticatedTestServer::actingAs($user, 'api')
        ->resource(EnterpriseContextResource::class, ['enterprise' => $enterprise->getKey()])
        ->assertOk()
        ->assertDontSee(['organization_id', 'enterprise_id', 'created_at', 'updated_at']);
});