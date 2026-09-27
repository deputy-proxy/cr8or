<?php

namespace Tests\Support\Agents;

use App\Agents\Agent;
use App\Agents\AgentDefinition;

final class RuntimeContractTestAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Runtime Contract Agent',
            description: 'Exercises invalid runtime contract behavior.',
            responsibilities: ['validate'],
            instructions: 'Validate runtime contract behavior.',
            experts: ['runtime-contract-expert'],
            requiredContext: ['enterprise'],
            capabilities: [],
        );
    }
}