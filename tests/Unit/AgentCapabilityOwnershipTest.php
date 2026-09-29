<?php

use App\Agents\CeoAgent;
use App\Agents\FinanceAgent;
use App\Agents\MarketingAgent;
use App\Agents\OperationsAgent;
use App\Agents\ProductAgent;
use App\Experts\StrategyExpert;

test('Agents do not own Capabilities directly', function () {
    foreach ([
        new CeoAgent,
        new FinanceAgent,
        new MarketingAgent,
        new OperationsAgent,
        new ProductAgent,
    ] as $agent) {
        expect(method_exists($agent, 'capabilities'))->toBeFalse();
        expect(property_exists($agent->definition(), 'capabilities'))->toBeFalse();
    }
});

test('Capabilities are declared by Experts', function () {
    expect((new StrategyExpert)->capabilities())->toContain('strategy.create');
});
