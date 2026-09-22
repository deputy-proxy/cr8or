<?php

use App\Agents\Agent;
use App\Experts\Expert;
use App\Models\ExpertDescriptor;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

it('persists an expert descriptor with registry fields only', function () {
    $descriptor = ExpertDescriptor::factory()->create([
        'slug' => 'copywriting',
        'enabled' => true,
    ]);

    expect($descriptor->slug)->toBe('copywriting')
        ->and($descriptor->runtime_class)->toBe(Expert::class)
        ->and($descriptor->enabled)->toBeTrue()
        ->and($descriptor->getAttributes())->toHaveKeys([
            'id',
            'slug',
            'runtime_class',
            'enabled',
            'created_at',
            'updated_at',
        ]);
});

it('resolves a registered expert runtime class', function () {
    $descriptor = ExpertDescriptor::factory()->create();

    expect($descriptor->resolveRuntimeClass())->toBe(Expert::class);
});

it('rejects an invalid expert runtime class', function () {
    expect(fn () => ExpertDescriptor::factory()->create([
        'runtime_class' => Agent::class,
    ]))->toThrow(InvalidArgumentException::class);
});

it('enforces unique expert descriptor slugs and runtime classes', function () {
    ExpertDescriptor::factory()->create([
        'slug' => 'copywriting',
        'runtime_class' => Expert::class,
    ]);

    expect(fn () => ExpertDescriptor::factory()->create([
        'slug' => 'copywriting',
        'runtime_class' => Expert::class,
    ]))->toThrow(QueryException::class);
});

it('persists disabled state without granting authority', function () {
    $descriptor = ExpertDescriptor::factory()->disabled()->create();

    expect($descriptor->enabled)->toBeFalse()
        ->and($descriptor->getAttributes())->not->toHaveKey('permissions');
});
