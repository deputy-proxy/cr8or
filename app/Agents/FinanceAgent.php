<?php

namespace App\Agents;

final class FinanceAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(name: 'Finance', description: 'Coordinates governed financial analysis and financial operations', responsibilities: ['analyze financial context', 'coordinate financial expertise', 'identify approval-sensitive financial work'], instructions: 'Analyze authorized financial context, coordinate financial expertise, and identify approval-sensitive work without treating analysis as execution authority.', experts: ['finance'], requiredContext: ['enterprise', 'financial'], capabilities: ['finance.report.generate']);
    }
}
