<?php

use App\Models\Customer;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Goal;
use App\Models\Kpi;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\EnterpriseContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

it('assembles complete authorized Enterprise context into the canonical Agent contract', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Authorized Enterprise',
    ]);

    EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'description' => 'A software business.',
        'industry' => 'Software',
        'business_model' => 'Subscription',
        'target_market' => 'SMBs',
        'geography' => 'Europe',
        'additional_context' => ['priority' => 'growth'],
    ]);

    $goal = Goal::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Grow recurring revenue',
    ]);
    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Monthly recurring revenue',
    ]);
    $product = Product::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Core Product',
    ]);
    $customer = Customer::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Authorized Customer',
    ]);
    $partner = Partner::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Authorized Partner',
    ]);

    $context = app(EnterpriseContextAssembler::class)->assemble($user, $enterprise);
    $data = $context->section('enterprise_context')?->data;

    expect($context->sections())->toHaveCount(2)
        ->and($context->section('enterprise')?->data)->toMatchArray([
            'id' => $enterprise->getKey(),
            'name' => 'Authorized Enterprise',
        ])
        ->and($data['context'])->toMatchArray([
            'description' => 'A software business.',
            'industry' => 'Software',
            'business_model' => 'Subscription',
            'target_market' => 'SMBs',
            'geography' => 'Europe',
            'additional_context' => ['priority' => 'growth'],
        ])
        ->and($data['strategic_context']['goals'])->toHaveCount(1)
        ->and($data['strategic_context']['goals'][0])->toMatchArray([
            'id' => $goal->getKey(),
            'name' => 'Grow recurring revenue',
            'description' => $goal->description,
            'status' => 'active',
        ])
        ->and($data['strategic_context']['kpis'])->toHaveCount(1)
        ->and($data['strategic_context']['kpis'][0])->toMatchArray([
            'id' => $kpi->getKey(),
            'name' => 'Monthly recurring revenue',
            'definition' => $kpi->definition,
            'unit' => $kpi->unit,
            'target_value' => $kpi->target_value,
            'current_value' => $kpi->current_value,
            'status' => 'active',
        ])
        ->and($data['products'])->toHaveCount(1)
        ->and($data['products'][0])->toMatchArray([
            'id' => $product->getKey(),
            'name' => 'Core Product',
            'slug' => $product->slug,
            'status' => 'active',
        ])
        ->and($data['customers'])->toHaveCount(1)
        ->and($data['customers'][0])->toMatchArray([
            'id' => $customer->getKey(),
            'name' => 'Authorized Customer',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => 'active',
        ])
        ->and($data['partners'])->toHaveCount(1)
        ->and($data['partners'][0])->toMatchArray([
            'id' => $partner->getKey(),
            'name' => 'Authorized Partner',
            'email' => $partner->email,
            'phone' => $partner->phone,
            'status' => 'active',
        ])
        ->and($data)->toBeArray();
});

it('assembles a stable partial Enterprise context when optional records are absent', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $context = app(EnterpriseContextAssembler::class)->assemble($user, $enterprise);
    $data = $context->section('enterprise_context')?->data;

    expect($data)->toMatchArray([
        'context' => null,
        'strategic_context' => [
            'goals' => [],
            'kpis' => [],
        ],
        'products' => [],
        'customers' => [],
        'partners' => [],
    ]);
});

it('excludes records belonging to another Enterprise in the same authorized organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Goal::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Included goal']);
    Goal::factory()->create(['enterprise_id' => $otherEnterprise->getKey(), 'name' => 'Excluded goal']);
    Product::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Included product']);
    Product::factory()->create(['enterprise_id' => $otherEnterprise->getKey(), 'name' => 'Excluded product']);

    $data = app(EnterpriseContextAssembler::class)
        ->assemble($user, $enterprise)
        ->section('enterprise_context')?->data;

    expect($data['strategic_context']['goals'])->toHaveCount(1)
        ->and($data['strategic_context']['goals'][0]['name'])->toBe('Included goal')
        ->and($data['products'])->toHaveCount(1)
        ->and($data['products'][0]['name'])->toBe('Included product');
});

it('denies Enterprise context outside the user organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create([
        'organization_id' => $foreignOrganization->getKey(),
    ]);

    expect(fn () => app(EnterpriseContextAssembler::class)->assemble($user, $foreignEnterprise))
        ->toThrow(AuthorizationException::class);
});
