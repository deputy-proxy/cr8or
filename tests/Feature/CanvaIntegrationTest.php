<?php

use App\Contracts\CanvaClient;
use App\Data\CanvaDesignRequest;
use App\Exceptions\CanvaClientException;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\ExternalResource;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Providers\FakeCanvaClient;
use App\Services\CanvaService;
use Illuminate\Auth\Access\AuthorizationException;

function canvaContext(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $asset = Asset::factory()->create(['enterprise_id' => $enterprise->id]);

    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'canva',
        'external_account_id' => 'canva-user-1',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
        'metadata' => ['scopes' => ['design:content:write']],
    ]);

    return [$organization, $user, $enterprise, $connection, $asset];
}

function canvaRequest(): CanvaDesignRequest
{
    return new CanvaDesignRequest(
        'Campaign design',
        ['type' => 'preset', 'name' => 'doc'],
    );
}

it('creates an organization-scoped Canva external resource through the provider boundary', function () {
    [$organization, $user, $enterprise, $connection, $asset] = canvaContext();

    $resource = app(CanvaService::class)->createDesign(
        $user,
        $connection,
        canvaRequest(),
        'canva-design-1',
        null,
        $asset,
    );

    expect($resource->provider)->toBe('canva')
        ->and($resource->resource_type)->toBe('design')
        ->and($resource->enterprise_id)->toBe($enterprise->id)
        ->and($resource->integration_connection_id)->toBe($connection->id)
        ->and($resource->external_id)->not->toBeEmpty()
        ->and(IntegrationJob::query()->where('idempotency_key', 'canva-design-1')->value('status'))
        ->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('denies a Canva connection from another organization', function () {
    [$organization, $user, $enterprise, $connection, $asset] = canvaContext();
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $foreignConnection = IntegrationConnection::query()->create([
        'organization_id' => $foreignOrganization->id,
        'enterprise_id' => $foreignEnterprise->id,
        'provider' => 'canva',
        'external_account_id' => 'foreign-user',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    expect(fn () => app(CanvaService::class)->createDesign(
        $user,
        $foreignConnection,
        canvaRequest(),
        'cross-org',
        null,
        $asset,
    ))->toThrow(AuthorizationException::class);
});

it('retries the same idempotent Canva operation without creating duplicate CR8OR resources', function () {
    [$organization, $user, $enterprise, $connection, $asset] = canvaContext();
    $client = app(CanvaClient::class);
    expect($client)->toBeInstanceOf(FakeCanvaClient::class);

    /** @var FakeCanvaClient $client */
    $client->shouldTimeout = true;

    expect(fn () => app(CanvaService::class)->createDesign(
        $user,
        $connection,
        canvaRequest(),
        'retry-me',
        null,
        $asset,
    ))->toThrow(CanvaClientException::class);

    $client->shouldTimeout = false;

    $resource = app(CanvaService::class)->createDesign(
        $user,
        $connection,
        canvaRequest(),
        'retry-me',
        null,
        $asset,
    );

    expect($resource->id)->toBeGreaterThan(0)
        ->and(ExternalResource::query()->count())->toBe(1)
        ->and(IntegrationJob::query()->where('idempotency_key', 'retry-me')->value('attempts'))->toBe(2);
});

it('correlates Canva resources to content and Agent execution when supplied', function () {
    [$organization, $user, $enterprise, $connection, $asset] = canvaContext();
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create([
        'enterprise_id' => $enterprise->id,
        'marketing_strategy_id' => $strategy->id,
    ]);
    $content = ContentItem::factory()->create([
        'enterprise_id' => $enterprise->id,
        'campaign_id' => $campaign->id,
    ]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $user->id]);

    $resource = app(CanvaService::class)->createDesign(
        $user,
        $connection,
        canvaRequest(),
        'correlated',
        $content,
        $asset,
        $execution,
        'correlation-123',
    );

    expect($resource->content_item_id)->toBe($content->id)
        ->and($resource->asset_id)->toBe($asset->id)
        ->and($resource->agent_execution_id)->toBe($execution->id)
        ->and($resource->correlation_id)->toBe('correlation-123');
});

it('never stores a Canva access token in connection records', function () {
    [$organization, $user, $enterprise, $connection, $asset] = canvaContext();

    expect($connection->credential_reference)->toBe('default')
        ->and($connection->getAttributes())->not->toHaveKey('access_token')
        ->and($connection->getAttributes())->not->toHaveKey('client_secret')
        ->and(json_encode($connection->getAttributes()))->not->toContain('CANVA_ACCESS_TOKEN');
});
