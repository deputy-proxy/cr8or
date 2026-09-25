<?php

use App\Capabilities\CapabilityDefinition;
use App\Capabilities\CapabilityRegistry;
use App\Contracts\Operation;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Operations\CreateContentItem;
use App\Operations\CreateStrategy;
use App\Operations\CreateWorkItem;
use App\Operations\UpdateStrategy;
use InvalidArgumentException;

function capabilityDefinition(
    string $key,
    string $operation,
    string $tool,
    string $toolClass,
): CapabilityDefinition {
    return new CapabilityDefinition(
        key: $key,
        operation: $operation,
        tool: $tool,
        toolClass: $toolClass,
        inputContract: ['value' => 'string|required'],
        outputContract: ['success' => 'boolean'],
        authorizationRequirement: 'McpCapabilityAuthorizer::authorizeMutation',
        approvalRequirement: 'permission-dependent',
    );
}

it('resolves every governed Capability to one explicit Operation and Tool contract', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->all())->toHaveCount(13);

    foreach ($registry->all() as $key => $definition) {
        expect($definition->key)->toBe($key)
            ->and(is_a($definition->operation, Operation::class, true))->toBeTrue()
            ->and(is_a($definition->toolClass, Laravel\Mcp\Server\Tool::class, true))->toBeTrue()
            ->and($definition->tool)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
            ->and($definition->inputContract)->not->toBeEmpty()
            ->and($definition->outputContract)->not->toBeEmpty()
            ->and($definition->authorizationRequirement)->not->toBeEmpty()
            ->and($definition->approvalRequirement)->not->toBeEmpty();
    }
});

it('resolves every governed MCP Tool to exactly one Capability and Operation class', function () {
    $registry = app(CapabilityRegistry::class);

    foreach ($registry->all() as $definition) {
        $resolved = $registry->forTool($definition->toolClass);

        expect($resolved->key)->toBe($definition->key)
            ->and($resolved->tool)->toBe($definition->tool)
            ->and($resolved->operation)->toBe($definition->operation);
    }
});

it('resolves a Tool class using the authoritative registry mapping', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->forTool(CreateContentItemTool::class)->key)->toBe('marketing.content.create')
        ->and($registry->forTool(CreateContentItemTool::class)->operation)->toBe(CreateContentItem::class);
});

it('rejects unknown capabilities and tools before authorization can grant them', function () {
    $registry = app(CapabilityRegistry::class);

    expect(fn () => $registry->resolve('unknown.capability'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->forTool('unknown-tool'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate Capability identifiers', function () {
    $definitions = [
        capabilityDefinition('test.duplicate', CreateContentItem::class, 'create-content-item', CreateContentItemTool::class),
        capabilityDefinition('test.duplicate', CreateStrategy::class, 'create-strategy', CreateStrategyTool::class),
    ];

    expect(fn () => (new CapabilityRegistry($definitions))->all())
        ->toThrow(InvalidArgumentException::class, 'Duplicate Capability identifier [test.duplicate]');
});

it('rejects an Operation mapped from multiple Capabilities', function () {
    $definitions = [
        capabilityDefinition('test.first', CreateContentItem::class, 'create-content-item', CreateContentItemTool::class),
        capabilityDefinition('test.second', CreateContentItem::class, 'create-strategy', CreateStrategyTool::class),
    ];

    expect(fn () => (new CapabilityRegistry($definitions))->all())
        ->toThrow(InvalidArgumentException::class, 'Duplicate Operation [App\\Operations\\CreateContentItem]');
});

it('rejects a Tool identifier mapped from multiple definitions', function () {
    $definitions = [
        capabilityDefinition('test.first', CreateContentItem::class, 'shared-tool', CreateContentItemTool::class),
        capabilityDefinition('test.second', CreateStrategy::class, 'shared-tool', CreateStrategyTool::class),
    ];

    expect(fn () => (new CapabilityRegistry($definitions))->all())
        ->toThrow(InvalidArgumentException::class, 'Duplicate MCP Tool identifier [shared-tool]');
});

it('rejects a Tool class mapped to multiple Operations', function () {
    $definitions = [
        capabilityDefinition('test.first', CreateContentItem::class, 'first-tool', CreateContentItemTool::class),
        capabilityDefinition('test.second', UpdateStrategy::class, 'second-tool', CreateContentItemTool::class),
    ];

    expect(fn () => (new CapabilityRegistry($definitions))->all())
        ->toThrow(InvalidArgumentException::class, 'Duplicate MCP Tool class [App\\Mcp\\Tools\\CreateContentItemTool]');
});

it('keeps capability definitions independent from Expert ownership', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->resolve('work.item.create')->operation)->toBe(CreateWorkItem::class)
        ->and($registry->resolve('marketing.plan')->operation)->toBe(App\Operations\PlanMarketing::class);
});