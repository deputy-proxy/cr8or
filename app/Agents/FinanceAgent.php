<?php

namespace App\Agents;

final class FinanceAgent extends Agent
{
    public function name(): string
    {
        return 'Finance';
    }

    public function description(): string
    {
        return 'Coordinates governed financial analysis and financial operations.';
    }

    public function responsibilities(): array
    {
        return ['analyze financial context', 'coordinate financial expertise', 'identify approval-sensitive financial work'];
    }

    public function capabilities(): array
    {
        return ['finance.execute'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'financial'];
    }
}
