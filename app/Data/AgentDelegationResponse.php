<?php

namespace App\Data;

use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\User;

final readonly class AgentDelegationResponse
{
    /**
     * @param  array<string, mixed>  $targetContext
     */
    public function __construct(
        public User $actor,
        public AgentAssignment $sourceAssignment,
        public AgentDescriptor $sourceDescriptor,
        public AgentAssignment $targetAssignment,
        public AgentDescriptor $targetDescriptor,
        public string $capability,
        public string $prompt,
        public array $targetContext,
        public string $correlationId,
    ) {}
}