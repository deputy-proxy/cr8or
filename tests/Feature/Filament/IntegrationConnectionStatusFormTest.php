<?php

use App\Filament\Resources\IntegrationConnections\Pages\CreateIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\EditIntegrationConnection;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

it('defaults the read-only integration connection status to active and allows creation', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($user);

    Livewire::test(CreateIntegrationConnection::class)
        ->assertFormFieldExists('status', function (Select $field): bool {
            expect($field->isDisabled())->toBeTrue()
                ->and($field->isDehydrated())->toBeFalse()
                ->and($field->getDefaultState())->toBe(IntegrationConnection::STATUS_ACTIVE);

            return true;
        })
        ->fillForm([
            'organization_id' => $organization->id,
            'provider' => 'cr8or-media',
            'credential_reference' => 'cr8or-media-test-reference',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $connection = IntegrationConnection::query()->where('organization_id', $organization->id)->firstOrFail();

    expect($connection->status)->toBe(IntegrationConnection::STATUS_ACTIVE);
});

it('preserves an existing integration connection status when editing', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);
    $connection = IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'cr8or-media',
        'credential_reference' => 'cr8or-media-test-reference',
        'status' => IntegrationConnection::STATUS_DISABLED,
    ]);

    $this->actingAs($user);

    Livewire::test(EditIntegrationConnection::class, ['record' => $connection->getRouteKey()])
        ->assertFormFieldExists('status', function (Select $field): bool {
            expect($field->isDisabled())->toBeTrue()
                ->and($field->isDehydrated())->toBeFalse();

            return true;
        })
        ->fillForm(['credential_reference' => 'cr8or-media-updated-reference'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($connection->refresh()->status)->toBe(IntegrationConnection::STATUS_DISABLED);
});
