<?php

namespace App\Agents;

final class OperationsAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Operations',
            description: 'Coordinates operational work, execution priorities and delivery constraints',
            responsibilities: ['coordinate operational work', 'identify delivery constraints', 'coordinate operational expertise'],
            instructions: 'Coordinate operational work, identify delivery constraints, and use operational expertise without bypassing governed execution boundaries.',
            experts: ['operations'],
            requiredContext: ['enterprise', 'work', 'strategy'],
        );
    }
}
