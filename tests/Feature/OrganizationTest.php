<?php

use App\Models\Organization;
use Illuminate\Database\QueryException;

it('creates a valid organization with the factory', function () {
    $organization = Organization::factory()->create();

    expect($organization)->toBeInstanceOf(Organization::class)
        ->and($organization->exists)->toBeTrue();
});

it('persists required organization identity data', function () {
    $organization = Organization::factory()->create([
        'name' => 'Acme Corporation',
        'slug' => 'acme-corporation',
    ]);

    $organization->refresh();

    expect($organization->name)->toBe('Acme Corporation')
        ->and($organization->slug)->toBe('acme-corporation')
        ->and($organization->id)->toBeInt();
});

it('rejects duplicate organization slugs', function () {
    Organization::factory()->create(['slug' => 'acme-corporation']);

    expect(fn () => Organization::factory()->create(['slug' => 'acme-corporation']))
        ->toThrow(QueryException::class);
});

it('hydrates an organization correctly after persistence', function () {
    $created = Organization::factory()->create([
        'name' => 'Acme Corporation',
        'slug' => 'acme-corporation',
    ]);

    $organization = Organization::query()->findOrFail($created->id);

    expect($organization->getKey())->toBe($created->getKey())
        ->and($organization->name)->toBe($created->name)
        ->and($organization->slug)->toBe($created->slug);
});
