<?php

namespace Tests\Support\Agents;

use App\Experts\Expert;
use App\Experts\ExpertDefinition;

final class FailingContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Failing Expert',
            description: 'Fails during reasoning.',
            responsibilities: ['fail'],
            methodology: 'Deliberately failing test reasoning.',
            requiredContext: ['enterprise'],
            capabilities: [],
        );
    }

    public function analyze(array $context): array
    {
        throw new \RuntimeException('reasoning failed');
    }
}