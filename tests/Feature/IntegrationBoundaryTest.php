<?php

use App\Data\Integrations\CredentialReference;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\IntegrationBoundaryService;
use App\Services\IntegrationRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use LogicException;

function integrationBoundaryContext(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'canva',
        'external_account_id' => 'canva-user-1',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    return [$organization, $user, $enterprise, $connection];
}

it('defines a provider-independent integration registry for current external boundaries', function () {
    $registry = app(IntegrationRegistry::class);

    expect($registry->provider('canva')->integration)->toBe('creative')
        ->and($registry->provider('postiz')->integration)->toBe('publishing')
        ->and($registry->provider('cloudflare-r2')->integration)->toBe('storage')
        ->and($registry->provider('cr8or-media')->integration)->toBe('media')
        ->and($registry->provider('github')->integration)->toBe('source_control')
        ->and($registry->provider('n8n')->configurationFields)->toHaveCount(2)
        ->and($registry->provider('n8n')->configurationFields[0]->key)->toBe('webhook_url')
        ->and($registry->provider('n8n')->configurationFields[1]->key)->toBe('authentication_mode');

    $registry->assertOperation('creative', 'canva', 'design.create');

    expect(fn () => $registry->assertOperation('publishing', 'canva', 'publication.publish'))
        ->toThrow(LogicException::class);
});

it('builds an enterprise-scoped external execution context through the canonical boundary', function () {
    [, $user, $enterprise, $connection] = integrationBoundaryContext();

    $context = app(IntegrationBoundaryService::class)->authorizeConnection(
        $user,
        $connection,
        $enterprise,
        'creative',
        'design.create',
        'correlation-1',
        'idempotency-1',
        ['asset_id' => 42],
    );

    expect($context->organizationId)->toBe($enterprise->organization_id)
        ->and($context->enterpriseId)->toBe($enterprise->id)
        ->and($context->integration)->toBe('creative')
        ->and($context->provider)->toBe('canva')
        ->and($context->correlationId)->toBe('correlation-1')
        ->and($context->idempotencyKey)->toBe('idempotency-1')
        ->and($context->resource)->toBe(['asset_id' => 42]);
});

it('rejects connections outside the enterprise and non-operational lifecycle states', function () {
    [$organization, $user, $enterprise, $connection] = integrationBoundaryContext();

    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignConnection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $otherEnterprise->id,
        'provider' => 'canva',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    expect(fn () => app(IntegrationBoundaryService::class)->authorizeConnection(
        $user,
        $foreignConnection,
        $enterprise,
        'creative',
        'design.create',
        'correlation-2',
        'idempotency-2',
    ))->toThrow(AuthorizationException::class);

    $connection->transitionTo(IntegrationConnection::STATUS_DEGRADED)->save();

    expect(fn () => app(IntegrationBoundaryService::class)->authorizeConnection(
        $user,
        $connection,
        $enterprise,
        'creative',
        'design.create',
        'correlation-3',
        'idempotency-3',
    ))->toThrow(AuthorizationException::class);

    $connection->transitionTo(IntegrationConnection::STATUS_REVOKED)->save();

    expect(fn () => $connection->transitionTo(IntegrationConnection::STATUS_ACTIVE))
        ->toThrow(LogicException::class);
});

it('validates provider configuration and keeps credential material out of it', function () {
    [$organization, $user, $enterprise] = integrationBoundaryContext();

    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'n8n',
        'credential_reference' => 'vault/n8n/default',
        'configuration' => [
            'webhook_url' => 'https://auto-task.up.railway.app/webhook/test',
            'authentication_mode' => 'none',
        ],
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    expect($connection->configuration)->toBe([
        'webhook_url' => 'https://auto-task.up.railway.app/webhook/test',
        'authentication_mode' => 'none',
    ]);

    expect(fn () => IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'n8n',
        'credential_reference' => 'vault/n8n/default',
        'configuration' => ['unknown' => 'value'],
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]))->toThrow(LogicException::class);

    expect(fn () => IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'n8n',
        'credential_reference' => 'vault/n8n/default',
        'configuration' => ['webhook_url' => 'https://example.com'],
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(fn () => IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'n8n',
        'credential_reference' => 'vault/n8n/default',
        'configuration' => [
            'webhook_url' => 'https://example.com',
            'authentication_mode' => 'none',
            'api_token' => 'do-not-store',
        ],
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]))->toThrow(LogicException::class);
});

it('keeps credential material out of connection metadata', function () {
    [$organization, $user, $enterprise] = integrationBoundaryContext();

    expect(fn () => IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'canva',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
        'metadata' => ['access_token' => 'do-not-store'],
    ]))->toThrow(LogicException::class);

    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'canva',
        'credential_reference' => 'vault/canva/default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
        'metadata' => ['scopes' => ['design:content:write']],
    ]);

    expect($connection->credentialReference())->toBeInstanceOf(CredentialReference::class)
        ->and($connection->credentialReference()->value())->toBe('vault/canva/default');
});

it('exposes canonical provider metadata through provider adapters', function () {
    $canva = app(\App\Contracts\CanvaClient::class);
    $publishing = app(\App\Contracts\PublishingProvider::class);

    expect($canva->integrationKey())->toBe('creative')
        ->and($canva->providerKey())->toBe('canva')
        ->and($canva->supports('design.create'))->toBeTrue()
        ->and($publishing->integrationKey())->toBe('publishing')
        ->and($publishing->providerKey())->toBe('postiz')
        ->and($publishing->supports('publication.publish'))->toBeTrue();
});

it('keeps the integration boundary schema enterprise scoped', function () {
    expect(Schema::hasColumns('integration_connections', [
        'organization_id',
        'enterprise_id',
        'provider',
        'credential_reference',
        'configuration',
        'status',
    ]))->toBeTrue();
});