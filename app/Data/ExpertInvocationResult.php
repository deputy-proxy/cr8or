<?php

namespace App\Data;

use App\AI\Contracts\ExecutionError;

final readonly class ExpertInvocationResult
{
    /**
     * @param  array<string, mixed>  $reasoningOutput
     * @param  list<CapabilityRequest>  $requestedCapabilities
     * @param  list<array<string, mixed>>  $decisions
     * @param  list<array<string, mixed>>  $recommendations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public string $expertSlug,
        public string $expertName,
        public string $runtimeClass,
        public int $agentExecutionId,
        public string $correlationId,
        public string $businessObjective,
        public array $reasoningOutput = [],
        public array $requestedCapabilities = [],
        public array $decisions = [],
        public array $recommendations = [],
        public array $metadata = [],
        public ?ExecutionError $failure = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expert' => [
                'slug' => $this->expertSlug,
                'name' => $this->expertName,
                'runtime_class' => $this->runtimeClass,
            ],
            'agent_execution_id' => $this->agentExecutionId,
            'correlation_id' => $this->correlationId,
            'business_objective' => $this->businessObjective,
            'reasoning_output' => $this->reasoningOutput,
            'requested_capabilities' => array_map(
                static fn (CapabilityRequest $request): array => $request->toArray(),
                $this->requestedCapabilities,
            ),
            'decisions' => $this->decisions,
            'recommendations' => $this->recommendations,
            'metadata' => $this->metadata,
            'failure' => $this->failure?->toArray($this->correlationId),
        ];
    }
}
