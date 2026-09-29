<?php

namespace App\Data;

use App\Enums\AgentExecutionMode;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\User;
use InvalidArgumentException;

final readonly class AgentExecutionRequest
{
    /**
     * @param  array<string, mixed>  $targetContext
     * @param  list<array<string, mixed>>  $capabilityRequests
     * @param  list<string>  $expertSlugs
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public User $actor,
        public AgentAssignment $assignment,
        public string $prompt,
        public AgentExecutionMode $mode = AgentExecutionMode::AUTONOMOUS,
        public array $capabilityRequests = [],
        public array $targetContext = [],
        public array $expertSlugs = [],
        public array $options = [],
        public ?string $correlationId = null,
        public ?AgentDelegation $delegation = null,
        public ?string $expertRoutingKey = null,
        public ?string $idempotencyKey = null,
        public bool $allowWorkerRetry = false,
    ) {
        foreach ($this->capabilityRequests as $request) {
            if (! isset($request['capability']) || ! is_string($request['capability']) || trim($request['capability']) === '') {
                throw new InvalidArgumentException('Interactive Agent execution Capability requests must contain a capability identifier.');
            }
            if (isset($request['target_context']) && ! is_array($request['target_context'])) {
                throw new InvalidArgumentException('Interactive Agent execution Capability target context must be an object.');
            }
            if (isset($request['input_payload']) && ! is_array($request['input_payload'])) {
                throw new InvalidArgumentException('Interactive Agent execution Capability input payload must be an object.');
            }
        }

        if ($this->mode !== AgentExecutionMode::INTERACTIVE && $this->capabilityRequests !== []) {
            throw new InvalidArgumentException('Capability requests may be supplied only for interactive Agent executions.');
        }

        if (trim($this->prompt) === '') {
            throw new InvalidArgumentException('An Agent execution prompt is required.');
        }

        foreach ($this->expertSlugs as $slug) {
            if (trim($slug) === '') {
                throw new InvalidArgumentException('Agent execution Expert identifiers must be non-empty strings.');
            }
        }

        if ($this->expertRoutingKey !== null && trim($this->expertRoutingKey) === '') {
            throw new InvalidArgumentException('Agent execution Expert routing keys must be non-empty strings.');
        }

        if ($this->expertRoutingKey !== null && $this->expertSlugs !== []) {
            throw new InvalidArgumentException('Agent execution must use either Expert routing metadata or explicit Expert identifiers, not both.');
        }

        if ($this->idempotencyKey !== null && trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Agent execution idempotency keys must be non-empty strings.');
        }
    }
}