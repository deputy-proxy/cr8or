<?php

namespace App\Agents;

final class ProductAgent extends Agent
{
    public function name(): string
    {
        return 'Product';
    }

    public function description(): string
    {
        return 'Coordinates product strategy, planning and delivery priorities.';
    }

    public function responsibilities(): array
    {
        return ['prioritize product work', 'coordinate product expertise', 'align product work with strategy'];
    }

    public function capabilities(): array
    {
        return ['strategy.create', 'strategy.update', 'work.create', 'work.update'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'strategy', 'knowledge', 'work'];
    }
}
