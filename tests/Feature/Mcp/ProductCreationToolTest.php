<?php

use App\Capabilities\CapabilityRegistry;
use App\Experts\ProductExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Operations\ProductCreate;
use App\Services\ExpertCapabilityResolver;

function productToolOwner(Organization $organization): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    return $user;
}

it('registers product creation through one capability and operation exposed by the Product Expert', function () {
    $definition = app(CapabilityRegistry::class)->forTool(CreateProductTool::class);

    expect($definition->key)->toBe('product.create')
        ->and($definition->operation)->toBe(ProductCreate::class)
        ->and($definition->tool)->toBe('create-product')
        ->and(app(ProductExpert::class)->capabilities())->toContain('product.create')
        ->and(app(ExpertCapabilityResolver::class)->resolve('product', 'product.create')->operation)->toBe(ProductCreate::class)
        ->and(app(CapabilityRegistry::class)->operationForTool(CreateProductTool::class))->toBeInstanceOf(ProductCreate::class);
});

it('creates a persisted enterprise-scoped product through the governed MCP boundary', function () {
    $organization = Organization::factory()->create();
    $actor = productToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => '  Valid Guide Reviews  ',
        ])
        ->assertOk()
        ->assertSee(['Valid Guide Reviews', 'valid-guide-reviews', 'active']);

    $product = Product::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    expect($product->name)->toBe('Valid Guide Reviews')
        ->and($product->slug)->toBe('valid-guide-reviews')
        ->and($product->status)->toBe(Product::STATUS_ACTIVE)
        ->and($product->enterprise->is($enterprise))->toBeTrue();
});

it('supports an explicit slug and archived status', function () {
    $organization = Organization::factory()->create();
    $actor = productToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Course Audit',
            'slug' => 'course-audit-v2',
            'status' => Product::STATUS_ARCHIVED,
        ])
        ->assertOk()
        ->assertSee(['course-audit-v2', 'archived']);

    expect(Product::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail())
        ->slug->toBe('course-audit-v2')
        ->status->toBe(Product::STATUS_ARCHIVED);
});

it('rejects duplicate slugs within an enterprise without affecting another enterprise', function () {
    $organization = Organization::factory()->create();
    $actor = productToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    Product::factory()->create(['enterprise_id' => $enterprise, 'slug' => 'same-slug']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Another Product',
            'slug' => 'same-slug',
        ])
        ->assertHasErrors();

    expect(Product::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Product::query()->where('enterprise_id', $otherEnterprise->getKey())->count())->toBe(0);
});

it('denies creation for an enterprise outside the authenticated user organization', function () {
    $actorOrganization = Organization::factory()->create();
    $actor = productToolOwner($actorOrganization);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $foreignEnterprise->getKey(),
            'name' => 'Must Not Persist',
        ])
        ->assertHasErrors();

    expect(Product::query()->where('enterprise_id', $foreignEnterprise->getKey())->exists())->toBeFalse();
});

it('denies product creation to a member without enterprise create permission', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $member->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => 'member',
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($member, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Unauthorized Product',
        ])
        ->assertHasErrors();

    expect(Product::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});
