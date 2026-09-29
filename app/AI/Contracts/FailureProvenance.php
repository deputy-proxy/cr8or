<?php

namespace App\AI\Contracts;

final readonly class FailureProvenance
{
    public function __construct(
        public ?string $operation = null,
        public ?string $capability = null,
        public ?string $tool = null,
    ) {}

    /** @return array{operation?: string, capability?: string, tool?: string} */
    public function toArray(): array
    {
        return array_filter([
            'operation' => $this->operation,
            'capability' => $this->capability,
            'tool' => $this->tool,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}