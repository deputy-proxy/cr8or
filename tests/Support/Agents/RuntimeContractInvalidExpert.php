<?php

namespace Tests\Support\Agents;

use App\Experts\Expert;
use App\Experts\ExpertDefinition;

final class RuntimeContractInvalidExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Invalid Contract Expert',
            description: 'Returns an invalid Capability request.',
            responsibilities: ['request'],
            methodology: 'Return invalid output.',
            requiredContext: ['enterprise'],
            capabilities: ['work.item.create'],
        );
    }

    public function analyze(array $context): array
    {
        return ['capability_requests' => [['input_payload' => []]]];
    }
}