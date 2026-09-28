<?php

namespace App\Data;

use App\Models\Enterprise;
use App\Models\User;
use InvalidArgumentException;

final readonly class KnowledgeRetrievalRequest
{
    /**
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $relevance
     */
    public function __construct(
        public User $actor,
        public Enterprise $enterprise,
        public ?string $query = null,
        public ?string $objective = null,
        public string $mode = 'default',
        public array $limits = [],
        public array $relevance = [],
        public ?string $correlationId = null,
    ) {
        if (($this->query === null || trim($this->query) === '')
            && ($this->objective === null || trim($this->objective) === '')) {
            throw new InvalidArgumentException('Knowledge retrieval requires a query or objective.');
        }

        if (trim($this->mode) === '') {
            throw new InvalidArgumentException('Knowledge retrieval mode is required.');
        }

        $limit = $this->limits['limit'] ?? 20;

        if (! is_int($limit) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Knowledge retrieval limit must be an integer between 1 and 50.');
        }

        if (array_key_exists('minimum_relevance', $this->relevance)
            && (! is_int($this->relevance['minimum_relevance']) && ! is_float($this->relevance['minimum_relevance']))) {
            throw new InvalidArgumentException('Knowledge retrieval minimum_relevance must be numeric.');
        }

        if ($this->correlationId !== null && trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Knowledge retrieval correlation_id must be non-empty when provided.');
        }
    }

    public function limit(): int
    {
        return $this->limits['limit'] ?? 20;
    }

    public function minimumRelevance(): float|int|null
    {
        return $this->relevance['minimum_relevance'] ?? null;
    }
}