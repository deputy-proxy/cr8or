<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class InteractiveReasoningResult
{
    /**
     * @param  list<array<string, mixed>>  $capabilityRequests
     * @param  list<array<string, mixed>>  $delegationRequests
     */
    public function __construct(
        public int $executionId,
        public int $expectedStep,
        public string $idempotencyKey,
        public string $reasoning,
        public array $capabilityRequests,
        public array $delegationRequests,
        public string $termination,
        public ?string $terminationReason,
    ) {
        if ($this->executionId < 1 || $this->expectedStep < 1 || trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Interactive reasoning results require execution, step, and idempotency identifiers.');
        }

        if (! in_array($this->termination, ['continue', 'waiting_for_input', 'waiting_for_approval', 'delegated', 'paused', 'completed'], true)) {
            throw new InvalidArgumentException('Invalid interactive termination state.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        return new self(
            executionId: (int) ($input['agent_execution_id'] ?? 0),
            expectedStep: (int) ($input['expected_step'] ?? 0),
            idempotencyKey: trim((string) ($input['idempotency_key'] ?? '')),
            reasoning: trim((string) ($input['reasoning'] ?? '')),
            capabilityRequests: array_values($input['capability_requests'] ?? []),
            delegationRequests: array_values($input['delegation_requests'] ?? []),
            termination: (string) ($input['termination'] ?? 'continue'),
            terminationReason: isset($input['termination_reason']) ? trim((string) $input['termination_reason']) : null,
        );
    }
}