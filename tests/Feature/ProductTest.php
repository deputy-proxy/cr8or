<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

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
        ->and(Gate::forUser($owner)->allows('create', [Product::class, $product->enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Product::class, $product->enterprise]))->toBeFalse();
});

it('keeps the product schema limited to phase 1 fields', function () {
    expect(Schema::getColumnListing('products'))->toBe([
        'id', 'enterprise_id', 'name', 'slug', 'status', 'created_at', 'updated_at',
    ]);
});
