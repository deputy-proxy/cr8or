<?php

namespace App\Data;

use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\User;
use InvalidArgumentException;

final readonly class CapabilityInvocationRequest
{
    /**
     * @param  array<string, mixed>  $targetContext
     * @param  array<string, mixed>  $inputPayload
     */
    public function __construct(
        public string $capability,
        public User $actor,
        public ?Enterprise $enterprise,
        public array $targetContext = [],
        public array $inputPayload = [],
        public ?AgentAssignment $assignment = null,
        public ?AgentExecution $execution = null,
        public ?ApprovalRequest $approval = null,
        public ?AgentDelegation $delegation = null,
        public ?string $correlationId = null,
        public ?string $idempotencyKey = null,
        public ?string $expertSlug = null,
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A Capability identifier is required.');
        }

        if (($this->assignment === null) !== ($this->execution === null)) {
            throw new InvalidArgumentException('Agent-backed Capability invocation requires both an assignment and execution context.');
        }

        if ($this->assignment !== null && $this->execution !== null) {
            if ($this->expertSlug === null || trim($this->expertSlug) === '') {
                throw new InvalidArgumentException('Agent-backed Capability invocation requires Expert provenance.');
            }
            if ($this->enterprise === null) {
                throw new InvalidArgumentException('Agent-backed Capability invocation requires an Enterprise context.');
            }

            if ($this->execution->agent_assignment_id !== $this->assignment->getKey()) {
                throw new InvalidArgumentException('A Capability invocation execution must belong to its Agent assignment.');
            }

            if ($this->execution->actor_id !== $this->actor->getKey()) {
                throw new InvalidArgumentException('A Capability invocation execution must belong to its actor.');
            }

            if ($this->execution->enterprise_id !== $this->enterprise->getKey()) {
                throw new InvalidArgumentException('A Capability invocation execution must belong to its Enterprise.');
            }
        }

        if ($this->expertSlug !== null && trim($this->expertSlug) === '') {
            throw new InvalidArgumentException('A Capability invocation Expert identifier must be non-empty.');
        }

        if ($this->correlationId !== null && trim($this->correlationId) === '') {
            throw new InvalidArgumentException('A Capability invocation correlation identifier must be non-empty.');
        }

        if ($this->correlationId !== null
            && $this->execution?->correlation_id !== null
            && $this->correlationId !== $this->execution->correlation_id
        ) {
            throw new InvalidArgumentException('A Capability invocation correlation identifier must match its Agent execution.');
        }

        if ($this->idempotencyKey !== null && trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('A Capability invocation idempotency key must be non-empty.');
        }

        if ($this->delegation !== null && $this->execution !== null
            && $this->delegation->parent_agent_execution_id !== $this->execution->getKey()
        ) {
            throw new InvalidArgumentException('A delegated Capability invocation must reference its parent Agent execution.');
        }
    }

    public function resolvedCorrelationId(): ?string
    {
        return $this->correlationId ?? $this->execution?->correlation_id;
    }

    public function isAgentBacked(): bool
    {
        return $this->assignment !== null && $this->execution !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'target_context' => $this->targetContext,
            'input_payload' => $this->inputPayload,
            'enterprise_id' => $this->enterprise->getKey(),
            'agent_assignment_id' => $this->assignment?->getKey(),
            'agent_execution_id' => $this->execution?->getKey(),
            'expert_slug' => $this->expertSlug,
            'correlation_id' => $this->resolvedCorrelationId(),
            'idempotency_key' => $this->idempotencyKey,
            'approval_request_id' => $this->approval?->getKey(),
            'delegation_id' => $this->delegation?->getKey(),
        ];
    }
}