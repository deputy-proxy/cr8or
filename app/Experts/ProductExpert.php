<?php

namespace App\Experts;

final class ProductExpert extends Expert
{
    public function name(): string
    {
        return 'Product';
    }

    public function description(): string
    {
        return 'Applies product planning methodology to authorized enterprise, strategy and work context.';
    }

    public function responsibilities(): array
    {
        return ['analyze product priorities', 'identify delivery trade-offs', 'support product planning'];
    }

    public function capabilities(): array
    {
        return ['strategy.create', 'strategy.update', 'work.create', 'work.update'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'strategy', 'knowledge', 'work'];
    }

    public function methodology(): string
    {
        return 'Outcome-first product planning grounded in strategy and delivery evidence.';
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'product planning',
            'available_context' => array_keys($context),
        ];
    }
}
