<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateEnterpriseTool;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('registers the create enterprise MCP tool for an organization member', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tools()
        ->assertRegistered([CreateEnterpriseTool::class]);
});

it('allows an organization owner to create an enterprise through MCP', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseTool::class, [
            'organization_id' => $organization->getKey(),
            'name' => 'Acme Corporation',
            'slug' => 'acme-corporation',
        ])
        ->assertOk()
        ->assertSee(['Acme Corporation', 'acme-corporation', 'active']);

    $enterprise = Enterprise::query()
        ->where('organization_id', $organization->getKey())
        ->where('slug', 'acme-corporation')
        ->firstOrFail();

    expect($enterprise->name)->toBe('Acme Corporation')
        ->and($enterprise->status)->toBe('active');
});

it('generates an enterprise slug when one is omitted', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->admin()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseTool::class, [
            'organization_id' => $organization->getKey(),
            'name' => 'Acme Global',
        ])
        ->assertOk();

    expect(Enterprise::query()
        ->where('organization_id', $organization->getKey())
        ->where('name', 'Acme Global')
        ->value('slug'))->toBe('acme-global');
});

it('denies enterprise creation to organization members without admin authority', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseTool::class, [
            'organization_id' => $organization->getKey(),
            'name' => 'Should Not Exist',
        ])
        ->assertHasErrors();

    expect(Enterprise::query()
        ->where('organization_id', $organization->getKey())
        ->where('name', 'Should Not Exist')
        ->exists())->toBeFalse();
});

it('denies enterprise creation in a foreign organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseTool::class, [
            'organization_id' => $foreignOrganization->getKey(),
            'name' => 'Foreign Enterprise',
        ])
        ->assertHasErrors();

    expect(Enterprise::query()
        ->where('organization_id', $foreignOrganization->getKey())
        ->where('name', 'Foreign Enterprise')
        ->exists())->toBeFalse();
});

it('validates enterprise creation input before persistence', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseTool::class, [
            'organization_id' => $organization->getKey(),
            'name' => '',
        ])
        ->assertHasErrors();

    expect(Enterprise::query()->where('organization_id', $organization->getKey())->count())->toBe(0);
});
