<?php

namespace Tests\Support\Agents;

use App\Agents\Agent;
use App\Agents\AgentDefinition;

final class CommunicationTestAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Communication Test Agent',
            description: 'Test-only Agent for validating execution-mode authorization.',
            responsibilities: ['test communication capability authorization'],
            instructions: 'Exercise the declared communication Expert without bypassing capability execution modes.',
            experts: ['communication'],
            requiredContext: ['enterprise'],
        );
    }
}
