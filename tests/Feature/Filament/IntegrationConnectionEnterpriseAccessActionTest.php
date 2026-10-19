<?php

use App\Filament\Resources\IntegrationConnections\Pages\ListIntegrationConnections;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function filamentAccessOAuthClient(): string
{
    $id = (string) Str::uuid();

    DB::table('oauth_clients')->insert([
        'id' => $id,
        'owner_type' => null,
        'owner_id' => null,
        'name' => 'CR8OR Media Filament test client',
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => '["client_credentials"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('lets an authorized administrator manage grants from the Integration Connections Filament table', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $clientId = filamentAccessOAuthClient();
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->getKey(),
        'provider' => 'cr8or-media',
        'oauth_client_id' => $clientId,
        'credential_reference' => 'media:filament-test',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $this->actingAs($actor);

    Livewire::test(ListIntegrationConnections::class)
        ->assertTableActionVisible('manageEnterpriseAccess', $connection)
        ->callTableAction('manageEnterpriseAccess', $connection, ['enterprise_ids' => [$enterprise->getKey()]])
        ->assertNotified('Enterprise access updated');

    expect(DB::table('integration_enterprise_access')
        ->where('oauth_client_id', $clientId)
        ->where('enterprise_id', $enterprise->getKey())
        ->where('active', true)
        ->exists())->toBeTrue();
});

it('does not expose Enterprise grant management on non-media connections', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->getKey(),
        'provider' => 'other-provider',
        'credential_reference' => 'other:filament-test',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $this->actingAs($actor);

    Livewire::test(ListIntegrationConnections::class)
        ->assertTableActionHidden('manageEnterpriseAccess', $connection);
});