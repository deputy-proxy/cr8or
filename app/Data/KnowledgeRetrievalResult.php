<?php

namespace App\Data;

final readonly class KnowledgeRetrievalResult
{
    /**
     * @param  list<KnowledgeRetrievalResultItem>  $items
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public string $correlationId,
        public array $items = [],
        public int $candidateCount = 0,
        public array $metadata = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function empty(): bool
    {
        return $this->items === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'correlation_id' => $this->correlationId,
            'candidate_count' => $this->candidateCount,
            'items' => array_map(
                static fn (KnowledgeRetrievalResultItem $item): array => $item->toArray(),
                $this->items,
            ),
            'metadata' => $this->metadata,
        ];
    }
}