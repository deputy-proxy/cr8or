<?php

use App\Filament\Resources\Audiences\Pages\CreateAudience;
use App\Filament\Resources\Audiences\Pages\EditAudience;
use App\Models\Audience;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;

it('persists structured persona attributes and same-enterprise product associations', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise]);

    $audience = app(DomainResourceService::class)->createAudience($user, $enterprise, [
        'name' => 'Practical buyer',
        'persona_type' => 'buyer',
        'age_range' => '30-49',
        'research_confidence' => 0.75,
        'psychographics' => ['values' => ['independence'], 'interests' => ['growth']],
        'goals_and_motivations' => ['goals' => ['generate qualified leads']],
        'pain_points' => ['problems' => ['inconsistent demand']],
        'buying_behavior' => ['preferred_channels' => ['email']],
        'product_ids' => [$product->id],
    ]);

    expect($audience->refresh()->persona_type)->toBe('buyer')
        ->and($audience->age_range)->toBe('30-49')
        ->and((float) $audience->research_confidence)->toBe(0.75)
        ->and($audience->psychographics['values'])->toBe(['independence'])
        ->and($audience->products->modelKeys())->toContain($product->id)
        ->and($product->refresh()->audiences->modelKeys())->toContain($audience->id);
});

it('preserves omitted persona attributes and product associations on partial update', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise]);
    $audience = Audience::factory()->create(['enterprise_id' => $enterprise, 'persona_type' => 'buyer', 'psychographics' => ['values' => ['independence']]]);
    $audience->products()->attach($product);

    $updated = app(DomainResourceService::class)->updateAudience($user, $audience, ['description' => 'Updated summary', 'psychographics' => ['interests' => ['growth']]]);

    expect($updated->description)->toBe('Updated summary')
        ->and($updated->persona_type)->toBe('buyer')
        ->and($updated->psychographics['values'])->toBe(['independence'])
        ->and($updated->psychographics['interests'])->toBe(['growth'])
        ->and($updated->products->modelKeys())->toContain($product->id);

    $cleared = app(DomainResourceService::class)->updateAudience($user, $updated, ['product_ids' => []]);
    expect($cleared->products)->toHaveCount(0);
});

it('rejects product associations from another enterprise through the domain service', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignProduct = Product::factory()->create(['enterprise_id' => $otherEnterprise]);

    expect(fn () => app(DomainResourceService::class)->createAudience($user, $enterprise, [
        'name' => 'Invalid persona',
        'product_ids' => [$foreignProduct->id],
    ]))->toThrow(ValidationException::class);
});

it('rejects cross-enterprise product association even when the pivot is written directly', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $audience = Audience::factory()->create(['enterprise_id' => $enterprise]);
    $product = Product::factory()->create(['enterprise_id' => $otherEnterprise]);

    expect(fn () => $audience->products()->attach($product->id))->toThrow(LogicException::class);
});

it('rejects invalid confidence values and unsupported persona type values', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    expect(fn () => app(DomainResourceService::class)->createAudience($user, $enterprise, [
        'name' => 'Invalid confidence',
        'research_confidence' => 1.5,
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(DomainResourceService::class)->createAudience($user, $enterprise, [
        'name' => 'Invalid type',
        'persona_type' => 'customer-record',
    ]))->toThrow(ValidationException::class);
});

it('requires authorization to create a persona in an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $unrelatedUser = User::factory()->create();

    expect(fn () => app(DomainResourceService::class)->createAudience($unrelatedUser, $enterprise, [
        'name' => 'Unauthorized persona',
    ]))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('creates and edits personas in Filament through the authorized domain service', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($user);

    Livewire::test(CreateAudience::class)
        ->fillForm([
            'enterprise_id' => $enterprise->id,
            'name' => 'Filament buyer persona',
            'persona_type' => 'buyer',
            'psychographics' => ['values' => ['practicality']],
            'product_ids' => [$product->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $audience = Audience::query()->where('name', 'Filament buyer persona')->firstOrFail();
    expect($audience->products->modelKeys())->toContain($product->id);

    Livewire::test(EditAudience::class, ['record' => $audience->getRouteKey()])
        ->fillForm(['name' => 'Updated Filament persona'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($audience->refresh()->name)->toBe('Updated Filament persona')
        ->and($audience->products->modelKeys())->toContain($product->id);
});