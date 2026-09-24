<?php

namespace App\Experts;

final class MarketingExpert extends Expert
{
    public function name(): string
    {
        return 'Marketing';
    }

    public function description(): string
    {
        return 'Applies marketing planning methodology to authorized enterprise and strategic context.';
    }

    public function responsibilities(): array
    {
        return ['analyze audience and positioning', 'identify campaign opportunities', 'support content planning'];
    }

    public function capabilities(): array
    {
        return ['marketing.plan'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'strategy', 'knowledge'];
    }

    public function methodology(): string
    {
        return 'Audience-first, strategy-aligned marketing analysis.';
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'marketing planning',
            'available_context' => array_keys($context),
        ];
    }
}