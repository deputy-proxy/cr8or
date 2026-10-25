<?php

use App\Capabilities\CapabilityRegistry;
use App\Experts\ProductExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\UpdateProductTool;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Operations\UpdateProduct;
use App\Services\ExpertCapabilityResolver;

function productUpdateOwner(Organization $organization): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    return $user;
}

it('registers product update through one governed capability and operation', function (): void {
    $definition = app(CapabilityRegistry::class)->forTool(UpdateProductTool::class);
    $schema = (new UpdateProductTool)->toArray()['inputSchema']['properties'];

    expect($definition->key)->toBe('product.update')
        ->and($definition->operation)->toBe(UpdateProduct::class)
        ->and($definition->tool)->toBe('update-product')
        ->and(app(ProductExpert::class)->capabilities())->toContain('product.update')
        ->and(app(ExpertCapabilityResolver::class)->resolve('product', 'product.update')->operation)->toBe(UpdateProduct::class)
        ->and(app(CapabilityRegistry::class)->operationForTool(UpdateProductTool::class))->toBeInstanceOf(UpdateProduct::class)
        ->and($schema['product_id']['type'])->toBe('integer')
        ->and($schema)->not->toHaveKey('enterprise_id');
});

it('updates only supplied fields and preserves product identity and omitted values', function (): void {
    $organization = Organization::factory()->create();
    $actor = productUpdateOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $product = Product::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Winter Camp',
        'slug' => 'winter-camp',
        'summary' => 'Existing summary',
        'description' => 'Existing description',
        'is_commercially_available' => false,
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateProductTool::class, [
            'product_id' => $product->getKey(),
            'summary' => 'Updated summary',
            'features' => ['English games', 'Creative workshops'],
        ])
        ->assertOk()
        ->assertSee(['Updated summary', 'English games', 'winter-camp']);

    $product->refresh();
    expect($product->getKey())->toBe($product->id)
        ->and($product->enterprise_id)->toBe($enterprise->getKey())
        ->and($product->name)->toBe('Winter Camp')
        ->and($product->slug)->toBe('winter-camp')
        ->and($product->summary)->toBe('Updated summary')
        ->and($product->description)->toBe('Existing description')
        ->and($product->features)->toBe(['English games', 'Creative workshops'])
        ->and($product->is_commercially_available)->toBeFalse();
});

it('rejects an empty update payload', function (): void {
    $organization = Organization::factory()->create();
    $actor = productUpdateOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateProductTool::class, ['product_id' => $product->getKey()])
        ->assertHasErrors();
});

it('rejects duplicate slugs without changing the product', function (): void {
    $organization = Organization::factory()->create();
    $actor = productUpdateOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->getKey(), 'slug' => 'winter-camp']);
    Product::factory()->create(['enterprise_id' => $enterprise->getKey(), 'slug' => 'other-camp']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateProductTool::class, ['product_id' => $product->getKey(), 'slug' => 'other-camp'])
        ->assertHasErrors();

    expect($product->fresh()->slug)->toBe('winter-camp');
});

it('denies updates to products outside the authenticated organization', function (): void {
    $organization = Organization::factory()->create();
    $actor = productUpdateOwner($organization);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $product = Product::factory()->create(['enterprise_id' => $foreignEnterprise->getKey(), 'summary' => 'Original']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateProductTool::class, ['product_id' => $product->getKey(), 'summary' => 'Unauthorized change'])
        ->assertHasErrors();

    expect($product->fresh()->summary)->toBe('Original');
});

it('does not permit callers to reassign product ownership', function (): void {
    $organization = Organization::factory()->create();
    $actor = productUpdateOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateProductTool::class, [
            'product_id' => $product->getKey(),
            'enterprise_id' => $otherEnterprise->getKey(),
            'summary' => 'Should not be accepted',
        ])
        ->assertHasErrors();

    expect($product->fresh()->enterprise_id)->toBe($enterprise->getKey())
        ->and($product->fresh()->summary)->not->toBe('Should not be accepted');
});

it('denies product updates to a member without enterprise update permission', function (): void {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $member->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => 'member',
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->getKey(), 'summary' => 'Original']);

    Cr8orServer::actingAs($member, 'api')
        ->tool(UpdateProductTool::class, ['product_id' => $product->getKey(), 'summary' => 'Unauthorized change'])
        ->assertHasErrors();

    expect($product->fresh()->summary)->toBe('Original');
});
