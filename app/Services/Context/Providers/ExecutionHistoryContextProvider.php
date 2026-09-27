<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\HistoricalContextAssembler;

final class ExecutionHistoryContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly HistoricalContextAssembler $assembler,
    ) {}

    public function requirements(): array
    {
        return ['execution_history'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        return [$this->assembler->executionHistory($user, $enterprise, $assignment)];
    }
}