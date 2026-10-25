<?php

namespace App\Experts;

final class ProductExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Product',
            description: 'Applies product planning methodology to authorized enterprise, strategy and work context.',
            responsibilities: ['analyze product priorities', 'identify delivery trade-offs', 'support product planning'],
            methodology: 'Outcome-first product planning grounded in strategy and delivery evidence.',
            requiredContext: ['enterprise', 'strategy', 'knowledge', 'work'],
            capabilities: ['product.create', 'product.update', 'product.list', 'strategy.create', 'strategy.update', 'work.task.create', 'work.task.update'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'product planning',
            'available_context' => array_keys($context),
        ];
    }
}