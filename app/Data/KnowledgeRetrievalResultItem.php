<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class KnowledgeRetrievalResultItem
{
    /**
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>|null  $context
     * @param  list<array<string, mixed>>  $references
     * @param  array<string, mixed>|null  $version
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int|string $knowledgeItemId,
        public string $title,
        public ?string $summary = null,
        public float|int|null $relevance = null,
        public ?array $source = null,
        public ?array $document = null,
        public ?array $context = null,
        public array $references = [],
        public ?array $version = null,
        public array $metadata = [],
    ) {
        if ($this->title === '') {
            throw new InvalidArgumentException('Knowledge retrieval result item title is required.');
        }

        if ($this->relevance !== null && $this->relevance < 0) {
            throw new InvalidArgumentException('Knowledge retrieval relevance cannot be negative.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'knowledge_item_id' => $this->knowledgeItemId,
            'title' => $this->title,
            'summary' => $this->summary,
            'relevance' => $this->relevance,
            'source' => $this->source,
            'document' => $this->document,
            'context' => $this->context,
            'references' => $this->references,
            'version' => $this->version,
            'metadata' => $this->metadata,
        ];
    }
}