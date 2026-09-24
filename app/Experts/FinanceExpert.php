<?php

namespace App\Experts;

final class FinanceExpert extends Expert
{
    public function name(): string
    {
        return 'Finance';
    }

    public function description(): string
    {
        return 'Applies financial analysis methodology to authorized enterprise financial context.';
    }

    public function responsibilities(): array
    {
        return ['analyze financial signals', 'identify financial risks', 'support financial decisions'];
    }

    public function capabilities(): array
    {
        return ['finance.execute'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'financial'];
    }

    public function methodology(): string
    {
        return 'Evidence-first financial analysis with explicit separation of analysis and execution authority.';
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'financial analysis',
            'available_context' => array_keys($context),
        ];
    }
}
