<?php

use App\Capabilities\CapabilityRegistry;
use App\Contracts\Operation;

it('resolves every governed Agent capability to one explicit Operation', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    foreach ($registry->all() as $key => $definition) {
        expect($definition->key)->toBe($key)
            ->and(is_a($definition->operation, Operation::class, true))->toBeTrue();
    }
});

it('rejects unknown capabilities before authorization can grant them', function () {
    $registry = app(CapabilityRegistry::class);

    expect(fn () => $registry->resolve('unknown.capability'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps capability definitions independent from Expert ownership', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->resolve('work.create')->operation)->toBe(App\Operations\CreateWorkItem::class)
        ->and($registry->resolve('marketing.plan')->operation)->toBe(App\Operations\PlanMarketing::class);
});
