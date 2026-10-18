<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

it('exposes the full Product domain in the authorized Filament create form', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $ownedEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $this->actingAs($user);

    $form = Livewire::test(CreateProduct::class)->assertStatus(200);
    foreach ([
        'enterprise_id', 'name', 'slug', 'status', 'product_type', 'tagline', 'summary', 'description',
        'problem_statement', 'value_proposition', 'primary_outcome', 'positioning', 'differentiators',
        'features', 'benefits', 'deliverables', 'requirements', 'limitations', 'delivery_model',
        'fulfillment_method', 'support_model', 'monetization_model', 'pricing_model', 'price_amount',
        'currency', 'billing_interval', 'commercial_terms', 'commercial_notes', 'is_commercially_available',
        'availability_start_at', 'availability_end_at', 'required_disclosures', 'restrictions',
    ] as $field) {
        $form->assertFormFieldExists($field);
    }

    $form->assertFormFieldExists('enterprise_id', function (Select $field) use ($ownedEnterprise, $foreignEnterprise): bool {
        $options = $field->getOptions();
        expect(array_key_exists((string) $ownedEnterprise->id, $options))->toBeTrue()
            ->and(array_key_exists((string) $foreignEnterprise->id, $options))->toBeFalse();

        return $field->getRelationshipName() === 'enterprise';
    });
});

it('scopes the Product resource query to enterprises in the authenticated user organizations', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    $ownedEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $ownedProduct = Product::factory()->create(['enterprise_id' => $ownedEnterprise->id]);
    Product::factory()->create(['enterprise_id' => $foreignEnterprise->id]);

    $this->actingAs($user);

    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownedProduct->id]);
});

it('creates a Product through the shared domain service when the Filament slug is omitted', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $this->actingAs($user);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'enterprise_id' => $enterprise->id,
            'name' => 'A Complete Product',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('enterprise_id', $enterprise->id)->firstOrFail();

    expect($product->name)->toBe('A Complete Product')
        ->and($product->slug)->toBe('a-complete-product')
        ->and($product->status)->toBe('active')
        ->and($product->product_type)->toBe('other')
        ->and($product->delivery_model)->toBe('other')
        ->and($product->monetization_model)->toBe('other')
        ->and($product->pricing_model)->toBe('other')
        ->and($product->is_commercially_available)->toBeFalse();
});

it('validates commercial and availability invariants in the Filament form before persistence', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $this->actingAs($user);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'enterprise_id' => $enterprise->id,
            'name' => 'Invalid Commercial Product',
            'pricing_model' => 'free',
            'monetization_model' => 'subscription',
            'price_amount' => 10,
            'currency' => 'USD',
            'availability_start_at' => '2026-12-01 00:00:00',
            'availability_end_at' => '2026-11-01 00:00:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['price_amount', 'billing_interval', 'availability_end_at']);

    expect(Product::query()->where('enterprise_id', $enterprise->id)->exists())->toBeFalse();
});
