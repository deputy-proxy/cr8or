<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateEnterpriseContextTool;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('registers the create enterprise context MCP tool for an organization member', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tools()
        ->assertRegistered([CreateEnterpriseContextTool::class]);
});

it('allows an organization admin to create enterprise context through MCP', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    Membership::factory()->admin()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'description' => 'A software company serving small businesses.',
            'industry' => 'Software',
            'business_model' => 'subscription',
            'target_market' => 'Small and medium businesses',
            'geography' => 'Romania',
            'additional_context' => ['values' => ['simplicity', 'trust']],
        ])
        ->assertOk()
        ->assertSee(['Software', 'subscription', 'Romania']);

    $context = EnterpriseContext::query()
        ->where('enterprise_id', $enterprise->getKey())
        ->firstOrFail();

    expect($context->description)->toBe('A software company serving small businesses.')
        ->and($context->additional_context)->toBe(['values' => ['simplicity', 'trust']]);
});

it('denies enterprise context creation to organization members without admin authority', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'industry' => 'Software',
        ])
        ->assertHasErrors();

    expect(EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('denies enterprise context creation for an enterprise in a foreign organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'industry' => 'Software',
        ])
        ->assertHasErrors();

    expect(EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('rejects a second enterprise context because the relationship is one-to-one', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'industry' => 'Software',
        ])
        ->assertHasErrors();

    expect(EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1);
});

it('validates enterprise context input before persistence', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateEnterpriseContextTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'additional_context' => 'not-an-object',
        ])
        ->assertHasErrors();

    expect(EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});
