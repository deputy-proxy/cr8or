<?php

namespace Tests\Support\Agents;

use App\Experts\Expert;
use App\Experts\ExpertDefinition;

final class RuntimeContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Runtime Contract Expert',
            description: 'Exercises the Capability Request contract.',
            responsibilities: ['request'],
            methodology: 'Return one governed Capability request.',
            requiredContext: ['enterprise'],
            capabilities: ['work.item.create'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'answer' => 'Contract request.',
            'capability_requests' => [[
                'capability' => 'work.item.create',
                'target_context' => ['enterprise_id' => $context['enterprise']['enterprise']['id']],
                'input_payload' => ['name' => 'Contract work item'],
            ]],
        ];
    }
}