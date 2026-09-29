<?php

namespace App\Agents;

final class ProductAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Product',
            description: 'Coordinates product strategy, planning and delivery priorities',
            responsibilities: ['prioritize product work', 'coordinate product expertise', 'align product work with strategy'],
            instructions: 'Prioritize product work, coordinate product expertise, and align delivery decisions with authorized strategy and work context.',
            experts: ['product'],
            requiredContext: ['enterprise', 'strategy', 'knowledge', 'work'],
        );
    }
}
