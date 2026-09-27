<?php

namespace App\Data;

use App\Agents\Agent;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\User;
use InvalidArgumentException;

final readonly class ExpertInvocationRequest
{
    /**
     * @param  array<string, mixed>  $authorizedContext
     * @param  array<string, mixed>  $targetContext
     */
    public function __construct(
        public User $actor,
        public AgentAssignment $assignment,
        public AgentExecution $execution,
        public Agent $agent,
        public string $expertSlug,
        public string $businessObjective,
        public array $authorizedContext,
        public string $expectedReasoningOutput,
        public array $targetContext = [],
        public ?string $correlationId = null,
    ) {
        if (trim($this->expertSlug) === '') {
            throw new InvalidArgumentException('An Expert identifier is required for invocation.');
        }

        if (trim($this->businessObjective) === '') {
            throw new InvalidArgumentException('An Expert invocation business objective is required.');
        }

        if (trim($this->expectedReasoningOutput) === '') {
            throw new InvalidArgumentException('An Expert invocation expected reasoning output is required.');
        }
    }
}
