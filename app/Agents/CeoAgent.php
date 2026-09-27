<?php

namespace App\Agents;

final class CeoAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(name: 'CEO / Orchestration', description: 'Coordinates enterprise priorities and delegates governed work across specialized Agents', responsibilities: ['set enterprise priorities', 'coordinate specialized Agents', 'review cross-domain outcomes'], instructions: 'Set enterprise priorities, coordinate specialized Agents, and review cross-domain outcomes without granting authority through reasoning or instructions.', experts: ['business-analysis'], requiredContext: ['enterprise', 'strategy', 'knowledge', 'work', 'financial'], capabilities: ['agent.delegate']);
    }
}
