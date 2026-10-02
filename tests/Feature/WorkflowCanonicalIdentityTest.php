<?php

use App\Models\Enterprise;
use App\Models\Workflow;
use Illuminate\Database\QueryException;

it('retrieves a Workflow deterministically by enterprise and canonical key', function (): void {
    $enterprise = Enterprise::factory()->create();
    $other = Enterprise::factory()->create();

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'canonical_key' => 'marketing.strategy.create',
    ]);
    Workflow::factory()->create([
        'enterprise_id' => $other->getKey(),
        'canonical_key' => 'marketing.strategy.create',
    ]);

    expect(Workflow::query()->forCanonicalKey($enterprise, 'marketing.strategy.create')->first()->getKey())
        ->toBe($workflow->getKey());
});

it('rejects duplicate canonical keys within one enterprise', function (): void {
    $enterprise = Enterprise::factory()->create();

    Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'canonical_key' => 'marketing.strategy.create',
    ]);

    expect(fn () => Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'canonical_key' => 'marketing.strategy.create',
    ]))->toThrow(QueryException::class);
});

it('allows existing workflows without a canonical key', function (): void {
    $enterprise = Enterprise::factory()->create();

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'canonical_key' => null,
    ]);

    expect($workflow->refresh()->canonical_key)->toBeNull();
});
