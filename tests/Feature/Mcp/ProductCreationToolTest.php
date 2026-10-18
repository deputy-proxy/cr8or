<?php

use App\Capabilities\CapabilityRegistry;
use App\Domain\Products\ProductInputRules;
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
    $expectedContractFields = array_values(array_filter(
        array_keys(ProductInputRules::rules()),
        static fn (string $key): bool => ! str_contains($key, '.'),
    ));
    $expectedContractFields[] = 'enterprise_id';
    $toolSchema = (new CreateProductTool)->toArray();
    $inputProperties = $toolSchema['inputSchema']['properties'];
    $outputProperties = $toolSchema['outputSchema']['properties']['result']['properties'];

    expect($definition->key)->toBe('product.create')
        ->and($definition->operation)->toBe(ProductCreate::class)
        ->and($definition->tool)->toBe('create-product')
        ->and(app(ProductExpert::class)->capabilities())->toContain('product.create')
        ->and(app(ExpertCapabilityResolver::class)->resolve('product', 'product.create')->operation)->toBe(ProductCreate::class)
        ->and(app(CapabilityRegistry::class)->operationForTool(CreateProductTool::class))->toBeInstanceOf(ProductCreate::class)
        ->and(array_keys($definition->inputContract))->toEqualCanonicalizing($expectedContractFields)
        ->and($definition->inputContract['product_type'])->toContain('digital_product')
        ->and($definition->inputContract['price_amount'])->toContain('currency required')
        ->and($inputProperties['summary']['type'])->toBe(['string', 'null'])
        ->and($inputProperties['product_type']['enum'])->toContain('digital_product')
        ->and($inputProperties['features']['items']['maxLength'])->toBe(500)
        ->and($inputProperties['price_amount']['multipleOf'])->toBe(0.01)
        ->and(array_keys($outputProperties))->toEqualCanonicalizing(array_merge(['id'], array_keys($inputProperties)))
        ->and($outputProperties['slug']['type'])->toBe('string')
        ->and($outputProperties['features']['type'])->toBe(['array', 'null'])
        ->and($toolSchema['outputSchema']['properties']['result']['required'])->toContain('price_amount', 'features', 'is_commercially_available');
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

it('creates the complete Product contract through the governed capability and returns persisted fields', function () {
    $organization = Organization::factory()->create();
    $actor = productToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Guided Learning',
            'status' => 'draft',
            'product_type' => 'course',
            'tagline' => 'Learn at your pace',
            'summary' => 'A structured learning path.',
            'description' => 'A complete course description.',
            'problem_statement' => 'Learners need a clear path.',
            'value_proposition' => 'Build skills with guided lessons.',
            'primary_outcome' => 'Complete the course.',
            'positioning' => 'Practical self-paced learning.',
            'differentiators' => ['Short lessons', 'Practical exercises'],
            'features' => ['Lessons', 'Quizzes'],
            'benefits' => ['Learn efficiently'],
            'deliverables' => ['Course access'],
            'requirements' => ['Internet access'],
            'limitations' => ['No live tutoring'],
            'delivery_model' => 'digital_self_service',
            'fulfillment_method' => 'Account access',
            'support_model' => 'Email support',
            'monetization_model' => 'subscription',
            'pricing_model' => 'subscription',
            'price_amount' => 19.99,
            'currency' => 'usd',
            'billing_interval' => 'monthly',
            'commercial_terms' => 'Cancel any time.',
            'commercial_notes' => 'Introductory offer.',
            'is_commercially_available' => true,
            'availability_start_at' => '2026-01-01T00:00:00Z',
            'availability_end_at' => '2026-12-31T23:59:59Z',
            'required_disclosures' => ['Results vary.'],
            'restrictions' => ['One account per learner.'],
        ])
        ->assertOk()
        ->assertSee(['Guided Learning', 'course', 'Learn at your pace', '19.99', 'USD', 'monthly', 'Results vary.']);

    $product = Product::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    expect($product->status)->toBe('draft')
        ->and($product->product_type)->toBe('course')
        ->and($product->tagline)->toBe('Learn at your pace')
        ->and($product->differentiators)->toBe(['Short lessons', 'Practical exercises'])
        ->and($product->features)->toBe(['Lessons', 'Quizzes'])
        ->and($product->price_amount)->toBe('19.99')
        ->and($product->currency)->toBe('USD')
        ->and($product->billing_interval)->toBe('monthly')
        ->and($product->is_commercially_available)->toBeTrue()
        ->and($product->required_disclosures)->toBe(['Results vary.'])
        ->and($product->restrictions)->toBe(['One account per learner.']);
});

it('rejects invalid commercial combinations through the governed Product creation operation', function () {
    $organization = Organization::factory()->create();
    $actor = productToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Invalid Free Product',
            'pricing_model' => 'free',
            'price_amount' => 10,
            'currency' => 'USD',
        ])
        ->assertHasErrors();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Invalid Subscription',
            'pricing_model' => 'subscription',
        ])
        ->assertHasErrors();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Invalid Currency',
            'price_amount' => 10,
            'currency' => 'ZZZ',
        ])
        ->assertHasErrors();

    expect(Product::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('enforces structured-list and text bounds through the MCP Product contract', function () {
    $organization = Organization::factory()->create();
    $actor = productToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Too Many Features',
            'features' => array_fill(0, 51, 'Feature'),
        ])
        ->assertHasErrors();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Overlong Feature',
            'features' => [str_repeat('x', 501)],
        ])
        ->assertHasErrors();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateProductTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Overlong Summary',
            'summary' => str_repeat('x', 1001),
        ])
        ->assertHasErrors();

    expect(Product::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});
