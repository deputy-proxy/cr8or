<?php

use App\Capabilities\CapabilityRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\DisableSocialAccountTool;
use App\Mcp\Tools\GetPublicationTool;
use App\Mcp\Tools\ListPublicationResultsTool;
use App\Mcp\Tools\ListPublicationTool;
use App\Mcp\Tools\ReconcilePublicationStatusTool;
use App\Mcp\Tools\UpdateSocialAccountTool;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;
use LogicException;

it('registers governed social and publication tools without generic connection CRUD', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    Cr8orServer::actingAs($user, 'api')
        ->tools()
        ->assertRegistered([
            UpdateSocialAccountTool::class,
            DisableSocialAccountTool::class,
            GetPublicationTool::class,
            ListPublicationTool::class,
            ListPublicationResultsTool::class,
            ReconcilePublicationStatusTool::class,
        ]);

    $registry = app(CapabilityRegistry::class);
    expect(fn () => $registry->resolve('integration.connection.create'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->resolve('marketing.social-account.connect'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->resolve('marketing.social-account.disconnect'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->forTool('create-connection'))
        ->toThrow(InvalidArgumentException::class);
});

it('requires social integration connections to be created through the OAuth lifecycle', function (): void {
    $organization = Organization::factory()->create();

    expect(fn () => IntegrationConnection::query()->create([
        'organization_id' => $organization->getKey(),
        'enterprise_id' => null,
        'provider' => 'x',
        'external_account_id' => '123456789012345678',
        'credential_reference' => 'secret://x/test-user',
        'configuration' => [],
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]))->toThrow(LogicException::class, 'created through the OAuth lifecycle');

    expect(IntegrationConnection::query()->where('provider', 'x')->exists())->toBeFalse();
});
