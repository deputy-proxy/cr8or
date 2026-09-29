<?php

namespace App\Data;

use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\User;
use InvalidArgumentException;

final readonly class CapabilityRequest
{
    /**
     * @param  array<string, mixed>  $targetContext
     * @param  array<string, mixed>  $inputPayload
     */
    public function __construct(
        public string $capability,
        public AgentAssignment $assignment,
        public AgentExecution $execution,
        public User $actor,
        public array $targetContext,
        public array $inputPayload,
        public string $expertSlug,
        public ?ApprovalRequest $approval = null,
        public ?string $correlationId = null,
        public ?string $idempotencyKey = null,
        public ?AgentDelegation $delegation = null,
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A Capability identifier is required.');
        }

        if ($this->execution->agent_assignment_id !== $this->assignment->getKey()) {
            throw new InvalidArgumentException('A Capability request execution must belong to its Agent assignment.');
        }

        if ($this->execution->actor_id !== $this->actor->getKey()) {
            throw new InvalidArgumentException('A Capability request execution must belong to its actor.');
        }

        if (trim($this->expertSlug) === '') {
            throw new InvalidArgumentException('A Capability request Expert identifier must be non-empty.');
        }

        if ($this->correlationId !== null && trim($this->correlationId) === '') {
            throw new InvalidArgumentException('A Capability request correlation identifier must be non-empty.');
        }

        if ($this->correlationId !== null
            && $this->execution->correlation_id !== null
            && $this->correlationId !== $this->execution->correlation_id
        ) {
            throw new InvalidArgumentException('A Capability request correlation identifier must match its Agent execution.');
        }

        if ($this->idempotencyKey !== null && trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('A Capability request idempotency key must be non-empty.');
        }

        if ($this->approval?->getKey() !== null && $this->approval->getKey() < 1) {
            throw new InvalidArgumentException('An approval request identifier must be positive.');
        }

        if ($this->delegation !== null && $this->delegation->parent_agent_execution_id !== $this->execution->getKey()) {
            throw new InvalidArgumentException('A delegated Capability request must reference its parent Agent execution.');
        }
    }

    public function resolvedCorrelationId(): ?string
    {
        return $this->correlationId ?? $this->execution->correlation_id;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'target_context' => $this->targetContext,
            'input_payload' => $this->inputPayload,
            'agent_assignment_id' => $this->assignment->getKey(),
            'agent_execution_id' => $this->execution->getKey(),
            'expert_slug' => $this->expertSlug,
            'correlation_id' => $this->resolvedCorrelationId(),
            'idempotency_key' => $this->idempotencyKey,
            'approval_request_id' => $this->approval?->getKey(),
            'delegation_id' => $this->delegation?->getKey(),
        ];
    }
}
