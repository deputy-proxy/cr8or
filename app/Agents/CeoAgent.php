<?php

namespace App\Agents;

final class CeoAgent extends Agent
{
    public function name(): string
    {
        return 'CEO / Orchestration';
    }

    public function description(): string
    {
        return 'Coordinates enterprise priorities and delegates governed work across specialized Agents.';
    }

    public function responsibilities(): array
    {
        return ['set enterprise priorities', 'coordinate specialized Agents', 'review cross-domain outcomes'];
    }

    public function capabilities(): array
    {
        return ['agent.delegate'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'strategy', 'knowledge', 'work', 'financial'];
    }
}
