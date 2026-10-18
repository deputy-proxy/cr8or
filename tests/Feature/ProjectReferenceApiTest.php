<?php

use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function projectReferenceClient(): Client
{
    return Client::factory()->asClientCredentials()->create();
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
    $allowed = Enterprise::factory()->create(['name' => 'Media Workspace', 'status' => 'active']);
    $archived = Enterprise::factory()->archived()->create(['name' => 'Archived Workspace']);
    $other = Enterprise::factory()->create(['name' => 'Other Tenant']);

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
    $allowed = Enterprise::factory()->create(['name' => 'Allowed']);
    $other = Enterprise::factory()->create(['name' => 'Not Allowed']);
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

it('rejects malformed and duplicate reference IDs', function (): void {
    $client = projectReferenceClient();
    Passport::actingAsClient($client, ['enterprise:read']);

    $this->postJson('/api/integrations/project-references/validate', ['ids' => []])->assertUnprocessable();
    $this->postJson('/api/integrations/project-references/validate', ['ids' => [1, 1]])->assertUnprocessable();
    $this->postJson('/api/integrations/project-references/validate', ['ids' => ['not-an-id']])->assertUnprocessable();
});
