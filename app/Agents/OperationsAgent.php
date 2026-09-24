<?php

namespace App\Agents;

final class OperationsAgent extends Agent
{
    public function name(): string
    {
        return 'Operations';
    }

    public function description(): string
    {
        return 'Coordinates operational work, execution priorities and delivery constraints.';
    }

    public function responsibilities(): array
    {
        return ['coordinate operational work', 'identify delivery constraints', 'coordinate operational expertise'];
    }

    public function capabilities(): array
    {
        return ['work.create', 'work.update'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'work', 'strategy'];
    }
}