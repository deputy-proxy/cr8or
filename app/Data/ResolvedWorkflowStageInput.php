<?php

namespace App\Data;

final readonly class ResolvedWorkflowStageInput
{
    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $mappings
     * @param  list<string>  $generated
     * @param  list<string>  $requested
     * @param  list<string>  $unresolved
     * @param  array<string, string>  $validation
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $inputs,
        public array $sources,
        public array $mappings,
        public array $generated = [],
        public array $requested = [],
        public array $unresolved = [],
        public array $validation = [],
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'sources' => $this->sources,
            'mappings' => $this->mappings,
            'generated' => $this->generated,
            'requested' => $this->requested,
            'unresolved' => $this->unresolved,
            'validation' => $this->validation,
            'metadata' => $this->metadata,
        ];
    }
}
