<?php

use App\Capabilities\CapabilityRegistry;
use App\Contracts\Operation;
use App\Mcp\Tools\CreateContentItemTool;

it('resolves every governed Agent capability to one explicit Operation', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    foreach ($registry->all() as $key => $definition) {
        expect($definition->key)->toBe($key)
            ->and(is_a($definition->operation, Operation::class, true))->toBeTrue()
            ->and($definition->tool)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
    }
});

it('resolves every governed MCP Tool to exactly one Capability and Operation class', function () {
    $registry = app(CapabilityRegistry::class);

    foreach ($registry->all() as $definition) {
        $resolved = $registry->forTool($definition->tool);

        expect($resolved->key)->toBe($definition->key)
            ->and($resolved->operation)->toBe($definition->operation);
    }
});

it('resolves a Tool class using its kebab-case MCP identifier', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->forTool(CreateContentItemTool::class)->key)->toBe('marketing.content.create')
        ->and($registry->forTool(CreateContentItemTool::class)->operation)
        ->toBe(App\Operations\CreateContentItem::class);
});

it('rejects unknown capabilities and tools before authorization can grant them', function () {
    $registry = app(CapabilityRegistry::class);

    expect(fn () => $registry->resolve('unknown.capability'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->forTool('unknown-tool'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps capability definitions independent from Expert ownership', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->resolve('work.item.create')->operation)->toBe(App\Operations\CreateWorkItem::class)
        ->and($registry->resolve('marketing.plan')->operation)->toBe(App\Operations\PlanMarketing::class);
});