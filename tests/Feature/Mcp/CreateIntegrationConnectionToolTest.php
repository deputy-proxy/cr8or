<?php

use App\Capabilities\CapabilityRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateConnectionTool;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('registers the create connection MCP tool for an organization member', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tools()
        ->assertRegistered([CreateConnectionTool::class]);
});

it('creates an enterprise-scoped integration connection through the governed capability', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateConnectionTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'provider' => 'n8n',
            'external_account_id' => 'marketing-webhook',
            'credential_reference' => 'secret://n8n/marketing-webhook',
            'configuration' => [
                'webhook_url' => 'https://auto-task.up.railway.app/webhook/test',
                'authentication_mode' => 'none',
            ],
        ])
        ->assertOk()
        ->assertSee(['n8n', 'marketing-webhook', 'secret://n8n/marketing-webhook', 'active']);

    $connection = IntegrationConnection::query()
        ->where('enterprise_id', $enterprise->getKey())
        ->where('provider', 'n8n')
        ->firstOrFail();

    expect($connection->organization_id)->toBe($organization->getKey())
        ->and($connection->external_account_id)->toBe('marketing-webhook')
        ->and($connection->credential_reference)->toBe('secret://n8n/marketing-webhook')
        ->and($connection->configuration)->toBe([
            'webhook_url' => 'https://auto-task.up.railway.app/webhook/test',
            'authentication_mode' => 'none',
        ])
        ->and($connection->status)->toBe(IntegrationConnection::STATUS_ACTIVE);
});

it('denies connection creation for an enterprise in another organization', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateConnectionTool::class, [
            'enterprise_id' => $foreignEnterprise->getKey(),
            'provider' => 'n8n',
            'credential_reference' => 'secret://n8n/foreign',
        ])
        ->assertHasErrors();

    expect(IntegrationConnection::query()
        ->where('enterprise_id', $foreignEnterprise->getKey())
        ->exists())->toBeFalse();
});

it('denies connection creation to a non-admin enterprise member', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateConnectionTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'provider' => 'n8n',
            'credential_reference' => 'secret://n8n/member',
        ])
        ->assertHasErrors();

    expect(IntegrationConnection::query()
        ->where('enterprise_id', $enterprise->getKey())
        ->exists())->toBeFalse();
});

it('rejects missing credential references before persistence', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(CreateConnectionTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'provider' => 'n8n',
        ])
        ->assertHasErrors();

    expect(IntegrationConnection::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('maps create connection to exactly one capability and operation', function (): void {
    $definition = app(CapabilityRegistry::class)->forTool(CreateConnectionTool::class);

    expect($definition->key)->toBe('integration.connection.create')
        ->and($definition->operation)->toBe(App\Operations\IntegrationConnectionCreate::class)
        ->and($definition->tool)->toBe('create-connection');
});
