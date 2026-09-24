<?php

namespace App\Experts;

final class OperationsExpert extends Expert
{
    public function name(): string
    {
        return 'Operations';
    }

    public function description(): string
    {
        return 'Applies operational planning methodology to authorized enterprise, strategy and work context.';
    }

    public function responsibilities(): array
    {
        return ['analyze operational constraints', 'identify delivery dependencies', 'support operational planning'];
    }

    public function capabilities(): array
    {
        return ['work.create', 'work.update'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'work', 'strategy'];
    }

    public function methodology(): string
    {
        return 'Constraint-first operational analysis grounded in current authorized work.';
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'operations planning',
            'available_context' => array_keys($context),
        ];
    }
}
