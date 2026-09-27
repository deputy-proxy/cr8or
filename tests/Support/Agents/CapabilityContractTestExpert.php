<?php

namespace Tests\Support\Agents;

use App\Experts\Expert;
use App\Experts\ExpertDefinition;

final class CapabilityContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Capability Expert',
            description: 'Requests a governed Capability.',
            responsibilities: ['request'],
            methodology: 'Evidence-first capability request.',
            requiredContext: ['enterprise'],
            capabilities: ['work.item.create'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'answer' => 'Requesting governed work.',
            'capability_requests' => [
                [
                    'capability' => 'work.item.create',
                    'target_context' => ['enterprise_id' => $context['enterprise']['enterprise']['id']],
                    'input_payload' => ['name' => 'Requested work item'],
                ],
            ],
        ];
    }
}