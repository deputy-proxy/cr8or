<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Persona;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates a product belonging to an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $product = Product::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'CR8OR Core',
        'slug' => 'cr8or-core',
    ]);

    expect($product->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->products->contains($product))->toBeTrue()
        ->and($product->status)->toBe('active');
});

it('keeps product ownership when unrelated attributes change', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $product = Product::factory()->create(['enterprise_id' => $enterprise]);

    $product->update(['name' => 'Updated Product', 'status' => 'archived']);

    expect($product->refresh()->enterprise->is($enterprise))->toBeTrue()
        ->and($product->enterprise_id)->not->toBe($otherEnterprise->id);
});

it('rejects duplicate product slugs within an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    Product::factory()->create(['enterprise_id' => $enterprise, 'slug' => 'same-product']);

    expect(fn () => Product::factory()->create([
        'enterprise_id' => $enterprise,
        'slug' => 'same-product',
    ]))->toThrow(QueryException::class);
});

it('allows the same product slug in different enterprises', function () {
    $enterprises = Enterprise::factory()->count(2)->create();

    Product::factory()->create(['enterprise_id' => $enterprises[0], 'slug' => 'same-product']);
    Product::factory()->create(['enterprise_id' => $enterprises[1], 'slug' => 'same-product']);

    expect(Product::query()->where('slug', 'same-product')->count())->toBe(2);
});

it('enforces product authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $product = Product::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $organization])]);
    $foreignProduct = Product::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization])]);

    expect(Gate::forUser($owner)->allows('view', $product))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $product))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $product))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $product))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignProduct))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Product::class, $product->enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Product::class, $product->enterprise]))->toBeFalse();
});

it('defines the complete product domain schema without dropping legacy identity columns', function () {
    expect(Schema::getColumnListing('products'))->toEqualCanonicalizing([
        'id', 'enterprise_id', 'name', 'slug', 'status', 'created_at', 'updated_at',
        'product_type', 'tagline', 'summary', 'description', 'problem_statement', 'value_proposition',
        'primary_outcome', 'positioning', 'differentiators', 'features', 'benefits', 'deliverables',
        'requirements', 'limitations', 'delivery_model', 'fulfillment_method', 'support_model',
        'monetization_model', 'pricing_model', 'price_amount', 'currency', 'billing_interval',
        'commercial_terms', 'commercial_notes', 'is_commercially_available', 'availability_start_at',
        'availability_end_at', 'required_disclosures', 'restrictions',
    ]);
});

it('applies conservative defaults while preserving existing Product and Persona identity', function () {
    $enterprise = Enterprise::factory()->create();
    $persona = Persona::factory()->create(['enterprise_id' => $enterprise->id]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->id]);
    $productId = $product->id;
    $product->personas()->attach($persona->id);

    expect($product->refresh())
        ->product_type->toBe('other')
        ->delivery_model->toBe('other')
        ->monetization_model->toBe('other')
        ->pricing_model->toBe('other')
        ->is_commercially_available->toBeFalse()
        ->and($product->personas->modelKeys())->toBe([$persona->id]);

    $product->update(['tagline' => 'Clear outcomes', 'features' => ['Reporting', 'Automation']]);

    expect(Product::query()->findOrFail($productId)->personas->modelKeys())->toBe([$persona->id])
        ->and(Product::query()->findOrFail($productId)->features)->toBe(['Reporting', 'Automation']);
});

it('supports a complete inline commercial configuration and normalizes currency', function () {
    $product = Product::factory()->create([
        'status' => 'draft',
        'product_type' => 'course',
        'delivery_model' => 'digital_self_service',
        'monetization_model' => 'subscription',
        'pricing_model' => 'subscription',
        'price_amount' => '19.99',
        'currency' => 'usd',
        'billing_interval' => 'monthly',
        'features' => ['Lessons', 'Exercises'],
        'benefits' => ['Self-paced learning'],
        'required_disclosures' => ['Results vary by learner'],
        'is_commercially_available' => true,
        'availability_start_at' => '2026-01-01 00:00:00',
        'availability_end_at' => '2026-12-31 23:59:59',
    ]);

    expect($product->refresh())
        ->status->toBe('draft')
        ->product_type->toBe('course')
        ->price_amount->toBe('19.99')
        ->currency->toBe('USD')
        ->billing_interval->toBe('monthly')
        ->features->toBe(['Lessons', 'Exercises'])
        ->required_disclosures->toBe(['Results vary by learner'])
        ->is_commercially_available->toBeTrue();
});

it('rejects unsupported controlled Product values at persistence', function () {
    $enterprise = Enterprise::factory()->create();

    foreach ([
        ['product_type' => 'made_up_type'],
        ['delivery_model' => 'made_up_delivery'],
        ['monetization_model' => 'made_up_monetization'],
        ['pricing_model' => 'made_up_pricing'],
        ['billing_interval' => 'every_six_weeks'],
    ] as $invalidAttributes) {
        expect(fn () => Product::factory()->create(array_merge(
            ['enterprise_id' => $enterprise->id],
            $invalidAttributes,
        )))->toThrow(LogicException::class);
    }
});

it('allows quote-based offerings without inventing a fixed price and keeps lifecycle independent from availability', function () {
    $product = Product::factory()->create([
        'status' => 'discontinued',
        'product_type' => 'service',
        'monetization_model' => 'quote_based',
        'pricing_model' => 'quote_based',
        'price_amount' => null,
        'currency' => null,
        'is_commercially_available' => false,
    ]);

    expect($product->status)->toBe('discontinued')
        ->and($product->pricing_model)->toBe('quote_based')
        ->and($product->price_amount)->toBeNull()
        ->and($product->currency)->toBeNull()
        ->and($product->is_commercially_available)->toBeFalse();
});

it('rejects invalid commercial combinations and malformed structured lists at persistence', function () {
    $enterprise = Enterprise::factory()->create();

    expect(fn () => Product::factory()->create([
        'enterprise_id' => $enterprise->id, 'price_amount' => '10.00',
    ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'pricing_model' => 'free', 'price_amount' => '10.00', 'currency' => 'USD',
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'price_amount' => '10.001', 'currency' => 'USD',
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'pricing_model' => 'subscription', 'billing_interval' => null,
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'currency' => 'ZZZ',
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'currency' => 'XXX',
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'availability_start_at' => '2026-12-01', 'availability_end_at' => '2026-11-01',
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'features' => ['Valid', ['invalid' => 'shape']],
        ]))->toThrow(LogicException::class)
        ->and(fn () => Product::factory()->create([
            'enterprise_id' => $enterprise->id, 'status' => 'pending',
        ]))->toThrow(LogicException::class);
});

it('rejects invalid product statuses and enterprise ownership changes', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $product = Product::factory()->create(['enterprise_id' => $enterprise]);

    expect(fn () => $product->update(['status' => 'pending']))->toThrow(LogicException::class)
        ->and(fn () => $product->update(['enterprise_id' => $otherEnterprise->id]))->toThrow(LogicException::class);
});