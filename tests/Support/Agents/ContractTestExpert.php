<?php

namespace Tests\Support\Agents;

use App\Experts\Expert;
use App\Experts\ExpertDefinition;

final class ContractTestExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Contract Expert',
            description: 'Provides contract test reasoning.',
            responsibilities: ['analyze'],
            methodology: 'Evidence-first analysis.',
            requiredContext: ['enterprise', 'strategy'],
            capabilities: ['work.item.create'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'answer' => 'Reasoned result.',
            'decisions' => [['title' => 'Proceed', 'summary' => 'Context supports proceeding.']],
            'recommendations' => [['action' => 'Proceed']],
        ];
    }
}