<?php

use App\Capabilities\CapabilityRegistry;
use App\Experts\ProductExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreatePersonaTool;
use App\Mcp\Tools\ListProductsTool;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Persona;
use App\Models\Product;
use App\Models\User;
use App\Operations\ListProducts;
use App\Services\ExpertCapabilityResolver;

function productListingOwner(Organization $organization): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    return $user;
}

it('registers product listing through one read capability and operation exposed by the Product Expert', function (): void {
    $definition = app(CapabilityRegistry::class)->forTool(ListProductsTool::class);
    $toolSchema = (new ListProductsTool)->toArray();
    $inputProperties = $toolSchema['inputSchema']['properties'];

    expect($definition->key)->toBe('product.list')
        ->and($definition->operation)->toBe(ListProducts::class)
        ->and($definition->tool)->toBe('list-products')
        ->and($definition->category)->toBe('read')
        ->and(app(ProductExpert::class)->capabilities())->toContain('product.list')
        ->and(app(ExpertCapabilityResolver::class)->resolve('product', 'product.list')->operation)->toBe(ListProducts::class)
        ->and(app(CapabilityRegistry::class)->operationForTool(ListProductsTool::class))->toBeInstanceOf(ListProducts::class)
        ->and($inputProperties['enterprise_id']['type'])->toBe('integer')
        ->and($inputProperties['status']['enum'])->toContain('active', 'archived')
        ->and($inputProperties['per_page']['maximum'])->toBe(50);
});

it('lists only products belonging to the authorized enterprise and returns association-ready IDs', function (): void {
    $organization = Organization::factory()->create();
    $actor = productListingOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $product = Product::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Valid Guide Reviews',
        'slug' => 'valid-guide-reviews',
        'commercial_notes' => 'Do not expose this internal note.',
    ]);
    Product::factory()->create([
        'enterprise_id' => $otherEnterprise->getKey(),
        'name' => 'Foreign Enterprise Product',
        'slug' => 'foreign-enterprise-product',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListProductsTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee([
            (string) $product->getKey(),
            'Valid Guide Reviews',
            'valid-guide-reviews',
            (string) $enterprise->getKey(),
        ])
        ->assertDontSee(['Foreign Enterprise Product', 'Do not expose this internal note.']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreatePersonaTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Learner evaluating course options',
            'product_ids' => [$product->getKey()],
        ])
        ->assertOk();

    expect(Persona::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail()->products->modelKeys())
        ->toContain($product->getKey());
});

it('supports bounded pagination and status and search filters', function (): void {
    $organization = Organization::factory()->create();
    $actor = productListingOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Product::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Course Alpha',
        'slug' => 'course-alpha',
        'status' => Product::STATUS_ACTIVE,
    ]);
    Product::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Course Beta',
        'slug' => 'course-beta',
        'status' => Product::STATUS_ARCHIVED,
    ]);
    Product::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Unrelated Service',
        'slug' => 'unrelated-service',
        'status' => Product::STATUS_ACTIVE,
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListProductsTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'status' => Product::STATUS_ACTIVE,
            'search' => 'Course',
            'per_page' => 1,
            'page' => 1,
        ])
        ->assertOk()
        ->assertSee(['Course Alpha', '"total":1', '"per_page":1'])
        ->assertDontSee(['Course Beta', 'Unrelated Service']);
});

it('denies listing products for an enterprise outside the authenticated organization', function (): void {
    $organization = Organization::factory()->create();
    $actor = productListingOwner($organization);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    Product::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListProductsTool::class, ['enterprise_id' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
});
