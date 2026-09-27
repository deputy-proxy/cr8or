<?php

namespace App\Experts;

final class OperationsExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Operations',
            description: 'Applies operational planning methodology to authorized enterprise, strategy and work context.',
            responsibilities: ['analyze operational constraints', 'identify delivery dependencies', 'support operational planning'],
            methodology: 'Constraint-first operational analysis grounded in current authorized work.',
            requiredContext: ['enterprise', 'work', 'strategy'],
            capabilities: ['work.item.create', 'work.item.update'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'operations planning',
            'available_context' => array_keys($context),
        ];
    }
}