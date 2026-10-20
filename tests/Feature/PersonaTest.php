<?php

use App\Filament\Resources\Personas\Pages\CreatePersona;
use App\Filament\Resources\Personas\Pages\EditPersona;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\GetPersonaTool;
use App\Mcp\Tools\ListPersonaTool;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Persona;
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

    $persona = app(DomainResourceService::class)->createPersona($user, $enterprise, [
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

    expect($persona->refresh()->persona_type)->toBe('buyer')
        ->and($persona->age_range)->toBe('30-49')
        ->and((float) $persona->research_confidence)->toBe(0.75)
        ->and($persona->psychographics['values'])->toBe(['independence'])
        ->and($persona->products->modelKeys())->toContain($product->id)
        ->and($product->refresh()->personas->modelKeys())->toContain($persona->id);
});

it('preserves omitted persona attributes and product associations on partial update', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise]);
    $persona = Persona::factory()->create(['enterprise_id' => $enterprise, 'persona_type' => 'buyer', 'psychographics' => ['values' => ['independence']]]);
    $persona->products()->attach($product);

    $updated = app(DomainResourceService::class)->updatePersona($user, $persona, ['description' => 'Updated summary', 'psychographics' => ['interests' => ['growth']]]);

    expect($updated->description)->toBe('Updated summary')
        ->and($updated->persona_type)->toBe('buyer')
        ->and($updated->psychographics['values'])->toBe(['independence'])
        ->and($updated->psychographics['interests'])->toBe(['growth'])
        ->and($updated->products->modelKeys())->toContain($product->id);

    $cleared = app(DomainResourceService::class)->updatePersona($user, $updated, ['product_ids' => []]);
    expect($cleared->products)->toHaveCount(0);
});

it('rejects product associations from another enterprise through the domain service', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignProduct = Product::factory()->create(['enterprise_id' => $otherEnterprise]);

    expect(fn () => app(DomainResourceService::class)->createPersona($user, $enterprise, [
        'name' => 'Invalid persona',
        'product_ids' => [$foreignProduct->id],
    ]))->toThrow(ValidationException::class);
});

it('rejects cross-enterprise product association even when the pivot is written directly', function () {
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();
    $persona = Persona::factory()->create(['enterprise_id' => $enterprise]);
    $product = Product::factory()->create(['enterprise_id' => $otherEnterprise]);

    expect(fn () => $persona->products()->attach($product->id))->toThrow(LogicException::class);
});

it('rejects invalid confidence values and unsupported persona type values', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    expect(fn () => app(DomainResourceService::class)->createPersona($user, $enterprise, [
        'name' => 'Invalid confidence',
        'research_confidence' => 1.5,
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(DomainResourceService::class)->createPersona($user, $enterprise, [
        'name' => 'Invalid type',
        'persona_type' => 'customer-record',
    ]))->toThrow(ValidationException::class);
});

it('requires authorization to create a persona in an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $unrelatedUser = User::factory()->create();

    expect(fn () => app(DomainResourceService::class)->createPersona($unrelatedUser, $enterprise, [
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

    Livewire::test(CreatePersona::class)
        ->fillForm([
            'enterprise_id' => $enterprise->id,
            'name' => 'Filament buyer persona',
            'persona_type' => 'buyer',
            'psychographics' => ['values' => ['practicality']],
            'product_ids' => [$product->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $persona = Persona::query()->where('name', 'Filament buyer persona')->firstOrFail();
    expect($persona->products->modelKeys())->toContain($product->id);

    Livewire::test(EditPersona::class, ['record' => $persona->getRouteKey()])
        ->fillForm(['name' => 'Updated Filament persona'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($persona->refresh()->name)->toBe('Updated Filament persona')
        ->and($persona->products->modelKeys())->toContain($product->id);
});

it('returns the complete persona contract from list and get MCP tools', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise]);
    $persona = Persona::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Complete persona contract',
        'persona_type' => 'buyer',
        'age_range' => '30-49',
        'location' => 'Bucharest',
        'job_title' => 'Founder',
        'income_range' => '100k-150k',
        'education_level' => 'University',
        'family_status' => 'Parent',
        'research_confidence' => 0.85,
        'last_reviewed_at' => '2026-10-20',
        'psychographics' => ['values' => ['quality'], 'interests' => ['technology']],
        'goals_and_motivations' => ['goals' => ['grow revenue']],
        'pain_points' => ['problems' => ['limited time']],
        'buying_behavior' => ['preferred_channels' => ['email'], 'budget_sensitivity' => 'medium'],
    ]);
    $persona->products()->attach($product);

    $expected = [
        'Complete persona contract', 'persona_type', 'buyer', 'age_range', '30-49', 'location',
        'Bucharest', 'job_title', 'Founder', 'income_range', '100k-150k', 'education_level',
        'University', 'family_status', 'Parent', 'research_confidence', '0.85', 'last_reviewed_at',
        '2026-10-20', 'psychographics', 'quality', 'goals_and_motivations', 'grow revenue',
        'pain_points', 'limited time', 'buying_behavior', 'budget_sensitivity', 'medium', 'product_ids',
        (string) $product->id, 'created_at', 'updated_at',
    ];

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetPersonaTool::class, ['id' => $persona->id])
        ->assertOk()
        ->assertSee($expected);

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListPersonaTool::class, ['per_page' => 50])
        ->assertOk()
        ->assertSee($expected);
});

it('does not expose personas outside the authenticated organization through list or get', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignPersona = Persona::factory()->create(['enterprise_id' => $foreignEnterprise]);

    Cr8orServer::actingAs($user, 'api')
        ->tool(GetPersonaTool::class, ['id' => $foreignPersona->id])
        ->assertHasErrors();

    Cr8orServer::actingAs($user, 'api')
        ->tool(ListPersonaTool::class, ['enterprise_id' => $foreignEnterprise->id])
        ->assertOk()
        ->assertDontSee($foreignPersona->name);
});