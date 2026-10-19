<?php

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function projectReferenceClient(): Client
{
    return Client::factory()->asClientCredentials()->create();
}

function attachProjectReferenceMediaConnection(Client $client, Enterprise $enterprise, string $status = IntegrationConnection::STATUS_ACTIVE): IntegrationConnection
{
    return IntegrationConnection::query()->create([
        'organization_id' => $enterprise->organization_id,
        'provider' => 'media',
        'oauth_client_id' => $client->getKey(),
        'credential_reference' => 'media:project-reference-test-'.$enterprise->getKey(),
        'status' => $status,
    ]);
}

function grantProjectReferenceAccess(Client $client, Enterprise $enterprise, bool $active = true): void
{
    DB::table('integration_enterprise_access')->insert([
        'oauth_client_id' => $client->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('requires a valid client-credentials token and explicit read scope', function (): void {
    $this->getJson('/api/integrations/project-references')->assertUnauthorized();
    $this->withToken('invalid-token')->getJson('/api/integrations/project-references')->assertUnauthorized();

    $client = projectReferenceClient();
    Passport::actingAsClient($client, []);

    $this->getJson('/api/integrations/project-references')->assertForbidden();
});

it('rejects user-bound OAuth tokens even when they have the read scope', function (): void {
    $user = \App\Models\User::factory()->create();
    Passport::actingAs($user, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')->assertUnauthorized();
});

it('lists only explicitly assigned enterprise references with stable IDs and status labels', function (): void {
    $client = projectReferenceClient();
    $organization = \App\Models\Organization::factory()->create();
    $allowed = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Media Workspace', 'status' => 'active']);
    $archived = Enterprise::factory()->archived()->create(['organization_id' => $organization->id, 'name' => 'Archived Workspace']);
    $other = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Other Tenant']);
    attachProjectReferenceMediaConnection($client, $allowed);

    grantProjectReferenceAccess($client, $allowed);
    grantProjectReferenceAccess($client, $archived);
    grantProjectReferenceAccess($client, $other, false);

    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $allowed->id, 'name' => 'Media Workspace', 'status' => 'active'],
            ['id' => $archived->id, 'name' => 'Archived Workspace', 'status' => 'archived'],
        ]]);
});

it('validates requested IDs only within the client enterprise scope and hides cross-scope existence', function (): void {
    $client = projectReferenceClient();
    $organization = \App\Models\Organization::factory()->create();
    $allowed = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Allowed']);
    $other = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Not Allowed']);
    attachProjectReferenceMediaConnection($client, $allowed);
    grantProjectReferenceAccess($client, $allowed);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->postJson('/api/integrations/project-references/validate', ['ids' => [$allowed->id]])
        ->assertOk()
        ->assertExactJson(['data' => [['id' => $allowed->id, 'name' => 'Allowed', 'status' => 'active']]]);

    $outOfScope = $this->postJson('/api/integrations/project-references/validate', ['ids' => [$other->id]]);
    $outOfScope->assertNotFound();

    $unknown = $this->postJson('/api/integrations/project-references/validate', ['ids' => [999999]]);
    $unknown->assertNotFound();
    $outOfScope->assertSee('The requested resource was not found.');
    $unknown->assertSee('The requested resource was not found.');
});

it('requires an active media connection and scopes grants to its organization', function (): void {
    $client = projectReferenceClient();
    $organization = \App\Models\Organization::factory()->create();
    $allowed = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreign = Enterprise::factory()->create();
    attachProjectReferenceMediaConnection($client, $allowed);
    grantProjectReferenceAccess($client, $allowed);
    grantProjectReferenceAccess($client, $foreign);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')
        ->assertOk()
        ->assertExactJson(['data' => [[
            'id' => $allowed->id,
            'name' => $allowed->name,
            'status' => $allowed->status,
        ]]]);
});

it('denies access when the client has no active media connection even if grants remain active', function (): void {
    $client = projectReferenceClient();
    $enterprise = Enterprise::factory()->create();
    attachProjectReferenceMediaConnection($client, $enterprise, IntegrationConnection::STATUS_DISABLED);
    grantProjectReferenceAccess($client, $enterprise);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')->assertForbidden();
    $this->postJson('/api/integrations/project-references/validate', ['ids' => [$enterprise->id]])->assertForbidden();
});

it('denies access when the associated OAuth client has been revoked', function (): void {
    $client = projectReferenceClient();
    $enterprise = Enterprise::factory()->create();
    attachProjectReferenceMediaConnection($client, $enterprise);
    grantProjectReferenceAccess($client, $enterprise);
    DB::table('oauth_clients')->where('id', $client->getKey())->update(['revoked' => true]);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')->assertForbidden();
});

it('does not treat another integration provider as an active media connection', function (): void {
    $client = projectReferenceClient();
    $enterprise = Enterprise::factory()->create();
    IntegrationConnection::query()->create([
        'organization_id' => $enterprise->organization_id,
        'provider' => 'other-provider',
        'credential_reference' => 'other:project-reference-test',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    grantProjectReferenceAccess($client, $enterprise);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')->assertForbidden();
});

it('rejects malformed and duplicate reference IDs', function (): void {
    $client = projectReferenceClient();
    $enterprise = Enterprise::factory()->create();
    attachProjectReferenceMediaConnection($client, $enterprise);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->postJson('/api/integrations/project-references/validate', ['ids' => []])->assertUnprocessable();
    $this->postJson('/api/integrations/project-references/validate', ['ids' => [1, 1]])->assertUnprocessable();
    $this->postJson('/api/integrations/project-references/validate', ['ids' => ['not-an-id']])->assertUnprocessable();
});

it('rejects OAuth clients that do not support the client-credentials grant', function (): void {
    $client = Client::factory()->create();
    expect($client->hasGrantType('client_credentials'))->toBeFalse();

    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')->assertUnauthorized();
});

it('rejects a second Integration Connection association for the same OAuth client', function (): void {
    $client = projectReferenceClient();
    $enterprise = Enterprise::factory()->create();
    attachProjectReferenceMediaConnection($client, $enterprise);

    expect(fn () => attachProjectReferenceMediaConnection($client, $enterprise))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('isolates Enterprise grants between authenticated OAuth clients', function (): void {
    $organization = \App\Models\Organization::factory()->create();
    $firstClient = projectReferenceClient();
    $secondClient = projectReferenceClient();
    $firstEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $secondEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    attachProjectReferenceMediaConnection($firstClient, $firstEnterprise);
    attachProjectReferenceMediaConnection($secondClient, $secondEnterprise);
    grantProjectReferenceAccess($firstClient, $firstEnterprise);
    grantProjectReferenceAccess($secondClient, $secondEnterprise);

    Passport::actingAsClient($firstClient, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')
        ->assertOk()
        ->assertExactJson(['data' => [[
            'id' => $firstEnterprise->id,
            'name' => $firstEnterprise->name,
            'status' => $firstEnterprise->status,
        ]]]);
});

it('returns an empty successful collection when the active client has no active Enterprise grants', function (): void {
    $client = projectReferenceClient();
    $enterprise = Enterprise::factory()->create();
    attachProjectReferenceMediaConnection($client, $enterprise);
    grantProjectReferenceAccess($client, $enterprise, active: false);
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->getJson('/api/integrations/project-references')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
