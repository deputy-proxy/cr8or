<?php

namespace App\AI\Data;

use App\Data\CapabilityRequest;
use App\Models\AgentDecision;
use App\Models\AgentExecution;

final readonly class AgentExecutionResult
{
    /**
     * @param  list<CapabilityRequest>  $capabilityRequests
     */
    public function __construct(
        public AgentExecution $execution,
        public ?ModelResult $modelResult,
        public ?AgentDecision $decision,
        public array $capabilityRequests = [],
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->execution->status, [AgentExecution::STATUS_COMPLETED, AgentExecution::STATUS_SUCCEEDED], true);
    }
}