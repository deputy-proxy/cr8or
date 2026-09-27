<?php

namespace App\Experts;

final class FinanceExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Finance',
            description: 'Applies financial analysis methodology to authorized enterprise financial context.',
            responsibilities: ['analyze financial signals', 'identify financial risks', 'support financial decisions'],
            methodology: 'Evidence-first financial analysis with explicit separation of analysis and execution authority.',
            requiredContext: ['enterprise', 'financial'],
            capabilities: ['finance.report.generate'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'financial analysis',
            'available_context' => array_keys($context),
        ];
    }
}