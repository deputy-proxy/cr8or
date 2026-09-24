<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('creates an enterprise within an organization', function () {
    $organization = Organization::factory()->create();

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization,
        'name' => 'Acme Corporation',
        'slug' => 'acme-corporation',
    ]);

    expect($enterprise->organization->is($organization))->toBeTrue()
        ->and($enterprise->name)->toBe('Acme Corporation')
        ->and($enterprise->slug)->toBe('acme-corporation')
        ->and($enterprise->status)->toBe('active');
});

it('allows an organization to own multiple enterprises', function () {
    $organization = Organization::factory()->create();
    $enterprises = Enterprise::factory()->count(2)->create(['organization_id' => $organization]);

    expect($organization->enterprises)->toHaveCount(2)
        ->and($enterprises->pluck('organization_id')->unique()->all())->toBe([$organization->id]);
});

it('keeps enterprise data limited to core identity and lifecycle fields', function () {
    expect(Schema::getColumnListing('enterprises'))->toBe([
        'id', 'organization_id', 'name', 'slug', 'status', 'created_at', 'updated_at',
    ]);
});

it('rejects duplicate enterprise slugs within an organization', function () {
    $organization = Organization::factory()->create();
    Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'acme']);

    expect(fn () => Enterprise::factory()->create([
        'organization_id' => $organization,
        'slug' => 'acme',
    ]))->toThrow(QueryException::class);
});

it('allows the same enterprise slug in different organizations', function () {
    $organizations = Organization::factory()->count(2)->create();

    Enterprise::factory()->create(['organization_id' => $organizations[0], 'slug' => 'acme']);
    Enterprise::factory()->create(['organization_id' => $organizations[1], 'slug' => 'acme']);

    expect(Enterprise::query()->where('slug', 'acme')->count())->toBe(2);
});

it('enforces enterprise authorization through organization membership', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->admin()->create(['user_id' => $admin, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    expect(Gate::forUser($owner)->allows('view', $enterprise))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $enterprise))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $enterprise))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $enterprise))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignEnterprise))->toBeFalse();
});

it('allows enterprise creation only to organization administrators', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    expect(Gate::forUser($owner)->allows('createForOrganization', [Enterprise::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForOrganization', [Enterprise::class, $organization]))->toBeFalse();
});
