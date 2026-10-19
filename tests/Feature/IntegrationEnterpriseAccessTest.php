<?php

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\IntegrationEnterpriseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function integrationAccessOAuthClient(array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('oauth_clients')->insert(array_merge([
        'id' => $id,
        'owner_type' => null,
        'owner_id' => null,
        'name' => 'CR8OR Media test client',
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => '["client_credentials"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides, ['id' => $id]));

    return $id;
}

function mediaConnectionForAccess(Organization $organization, string $clientId): IntegrationConnection
{
    return IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'media',
        'oauth_client_id' => $clientId,
        'credential_reference' => 'media-test-credential',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
}

function enterpriseAccessOwner(Organization $organization): User
{
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    return $user;
}

it('associates a media connection with an OAuth client without implicitly granting Enterprises', function () {
    $organization = Organization::factory()->create();
    $clientId = integrationAccessOAuthClient();
    $connection = mediaConnectionForAccess($organization, $clientId);

    expect($connection->fresh()->oauthClient->getKey())->toBe($clientId)
        ->and(DB::table('integration_enterprise_access')->where('oauth_client_id', $clientId)->count())->toBe(0);
});

it('grants and revokes only authorized Enterprises in the connection organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $actor = enterpriseAccessOwner($organization);
    $clientId = integrationAccessOAuthClient();
    $connection = mediaConnectionForAccess($organization, $clientId);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization->id]);
    $service = app(IntegrationEnterpriseAccessService::class);

    $this->actingAs($actor);
    $service->syncForConnection($actor, $connection, [$enterprise->id, $otherEnterprise->id]);

    expect(DB::table('integration_enterprise_access')
        ->where('oauth_client_id', $clientId)
        ->where('active', true)
        ->pluck('enterprise_id')
        ->map(fn ($id): int => (int) $id)
        ->all())->toEqualCanonicalizing([$enterprise->id, $otherEnterprise->id]);

    $service->syncForConnection($actor, $connection, [$otherEnterprise->id]);

    expect(DB::table('integration_enterprise_access')
        ->where('oauth_client_id', $clientId)
        ->where('enterprise_id', $enterprise->id)
        ->value('active'))->toBe(0)
        ->and(DB::table('integration_enterprise_access')
            ->where('oauth_client_id', $clientId)
            ->where('enterprise_id', $otherEnterprise->id)
            ->value('active'))->toBe(1);

    expect(fn () => $service->syncForConnection($actor, $connection, [$foreignEnterprise->id]))
        ->toThrow(ValidationException::class);
});

it('rejects Enterprise access management for non-media connections and unlinked media connections', function () {
    $organization = Organization::factory()->create();
    $actor = enterpriseAccessOwner($organization);
    $clientId = integrationAccessOAuthClient();
    $mediaConnection = mediaConnectionForAccess($organization, $clientId);
    $nonMediaConnection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'other-provider',
        'credential_reference' => 'other-test-credential',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $unlinkedMediaConnection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'media',
        'credential_reference' => 'unlinked-test-credential',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $service = app(IntegrationEnterpriseAccessService::class);

    expect(fn () => $service->syncForConnection($actor, $nonMediaConnection, [$enterprise->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->syncForConnection($actor, $unlinkedMediaConnection, [$enterprise->id]))
        ->toThrow(ValidationException::class);

    expect($mediaConnection->oauth_client_id)->toBe($clientId);
});

it('does not allow a non-owner to grant Enterprise access', function () {
    $organization = Organization::factory()->create();
    $owner = enterpriseAccessOwner($organization);
    $member = User::factory()->create();
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id]);
    $clientId = integrationAccessOAuthClient();
    $connection = mediaConnectionForAccess($organization, $clientId);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);

    expect(fn () => app(IntegrationEnterpriseAccessService::class)->syncForConnection($member, $connection, [$enterprise->id]))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});