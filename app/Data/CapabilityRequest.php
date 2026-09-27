<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class CapabilityRequest
{
    /** @param array<string, mixed> $targetContext */
    public function __construct(
        public string $capability,
        public array $targetContext = [],
        public ?int $approvalRequestId = null,
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A Capability identifier is required.');
        }

        if ($this->approvalRequestId !== null && $this->approvalRequestId < 1) {
            throw new InvalidArgumentException('An approval request identifier must be positive.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'target_context' => $this->targetContext,
            'approval_request_id' => $this->approvalRequestId,
        ];
    }
}