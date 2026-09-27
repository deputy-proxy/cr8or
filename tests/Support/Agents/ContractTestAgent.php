<?php

namespace Tests\Support\Agents;

use App\Agents\Agent;
use App\Agents\AgentDefinition;

final class ContractTestAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Contract Agent',
            description: 'Tests the governed Expert invocation contract.',
            responsibilities: ['coordinate'],
            instructions: 'Coordinate Expert reasoning within authorized context.',
            experts: ['contract-expert', 'failing-expert', 'capability-expert'],
            requiredContext: ['enterprise'],
            capabilities: [],
        );
    }
}