<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\GetEnterpriseTool;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

function getEnterpriseActor(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    return [$user, $organization];
}

it('gets an enterprise by canonical slug', function () {
    [$user, $organization] = getEnterpriseActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'valid.guide', 'slug' => 'valid-guide']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetEnterpriseTool::class, ['slug' => 'valid-guide'])
        ->assertOk()
        ->assertSee([(string) $enterprise->getKey(), 'valid-guide', 'valid.guide']);
});

it('gets an enterprise by a human-entered name that normalizes to its canonical slug', function () {
    [$user, $organization] = getEnterpriseActor();
    Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'valid.guide', 'slug' => 'valid-guide']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetEnterpriseTool::class, ['slug' => 'valid.guide'])
        ->assertOk()
        ->assertSee('valid-guide');
});

it('keeps id lookup compatible and returns the authoritative identity pair', function () {
    [$user, $organization] = getEnterpriseActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'valid-guide']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetEnterpriseTool::class, ['id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee([(string) $enterprise->getKey(), 'valid-guide']);
});

it('fails closed when id and slug identify different enterprises', function () {
    [$user, $organization] = getEnterpriseActor();
    $valid = Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'valid-guide']);
    Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'blckdsgncom']);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetEnterpriseTool::class, ['id' => $valid->getKey(), 'slug' => 'blckdsgncom'])
        ->assertHasErrors();
});

it('fails closed for an ambiguous slug across authorized organizations', function () {
    $user = User::factory()->create();
    $organizations = Organization::factory()->count(2)->create();
    foreach ($organizations as $organization) {
        Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
        Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'shared']);
    }

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetEnterpriseTool::class, ['slug' => 'shared'])
        ->assertHasErrors();
});