<?php

namespace App\Data;

use App\Models\AgentAssignment;
use App\Models\ApprovalRequest;
use App\Models\User;

final readonly class AgentDelegationRequest
{
    /**
     * @param  array<string, mixed>  $targetContext
     */
    public function __construct(
        public User $actor,
        public AgentAssignment $sourceAssignment,
        public string $targetAgentSlug,
        public string $capability,
        public string $prompt,
        public array $targetContext = [],
        public ?ApprovalRequest $sourceApproval = null,
        public ?ApprovalRequest $targetApproval = null,
        public ?string $correlationId = null,
    ) {}
}