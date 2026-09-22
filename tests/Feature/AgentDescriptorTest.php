<?php

use App\Agents\Agent;
use App\Models\AgentDescriptor;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

it('persists an agent descriptor with registry fields only', function () {
    $descriptor = AgentDescriptor::factory()->create([
        'slug' => 'marketing',
        'enabled' => true,
    ]);

    expect($descriptor->slug)->toBe('marketing')
        ->and($descriptor->runtime_class)->toBe(Agent::class)
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

it('resolves a registered agent runtime class', function () {
    $descriptor = AgentDescriptor::factory()->create();

    expect($descriptor->resolveRuntimeClass())->toBe(Agent::class);
});

it('rejects an invalid agent runtime class', function () {
    expect(fn () => AgentDescriptor::factory()->create([
        'runtime_class' => \stdClass::class,
    ]))->toThrow(InvalidArgumentException::class);
});

it('enforces unique agent descriptor slugs and runtime classes', function () {
    AgentDescriptor::factory()->create([
        'slug' => 'marketing',
        'runtime_class' => Agent::class,
    ]);

    expect(fn () => AgentDescriptor::factory()->create([
        'slug' => 'marketing',
        'runtime_class' => Agent::class,
    ]))->toThrow(QueryException::class);
});

it('persists disabled state without granting authority', function () {
    $descriptor = AgentDescriptor::factory()->disabled()->create();

    expect($descriptor->enabled)->toBeFalse()
        ->and($descriptor->getAttributes())->not->toHaveKey('permissions');
});
