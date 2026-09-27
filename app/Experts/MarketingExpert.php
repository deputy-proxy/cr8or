<?php

namespace App\Experts;

final class MarketingExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Marketing',
            description: 'Applies marketing planning methodology to authorized enterprise and strategic context.',
            responsibilities: ['analyze audience and positioning', 'identify campaign opportunities', 'support content planning'],
            methodology: 'Audience-first, strategy-aligned marketing analysis.',
            requiredContext: ['enterprise', 'strategy', 'knowledge'],
            capabilities: ['marketing.plan'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'marketing planning',
            'available_context' => array_keys($context),
        ];
    }
}