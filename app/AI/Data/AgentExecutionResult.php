<?php

namespace App\AI\Data;

use App\Models\AgentDecision;
use App\Models\AgentExecution;

final readonly class AgentExecutionResult
{
    /**
     * @param  list<array<string, mixed>>  $capabilityRequests
     */
    public function __construct(
        public AgentExecution $execution,
        public ?ModelResult $modelResult,
        public ?AgentDecision $decision,
        public array $capabilityRequests = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->execution->status === AgentExecution::STATUS_SUCCEEDED;
    }
}
