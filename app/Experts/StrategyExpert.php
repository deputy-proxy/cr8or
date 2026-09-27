<?php

namespace App\Experts;

final class StrategyExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Strategy',
            description: 'Applies strategy methodology to authorized enterprise and strategic context without executing strategy operations.',
            responsibilities: [
                'align marketing work to strategy',
                'identify strategic trade-offs',
                'surface strategic constraints',
            ],
            methodology: 'Outcome-first strategy analysis grounded in authorized enterprise and strategy context.',
            requiredContext: ['enterprise', 'strategy'],
            capabilities: ['strategy.create', 'strategy.update'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'strategy alignment',
            'available_context' => array_keys($context),
        ];
    }
}