<?php

namespace App\Data;

final readonly class KnowledgeNormalizedUnit
{
    /**
     * @param  list<string>  $headingPath
     * @param  list<array<string, mixed>>  $references
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $enterpriseId,
        public ?int $sourceId,
        public ?int $documentId,
        public ?int $knowledgeItemId,
        public ?int $versionId,
        public string $unitKey,
        public int $ordinal,
        public string $content,
        public array $headingPath = [],
        public array $references = [],
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enterprise_id' => $this->enterpriseId,
            'source_id' => $this->sourceId,
            'document_id' => $this->documentId,
            'knowledge_item_id' => $this->knowledgeItemId,
            'version_id' => $this->versionId,
            'unit_key' => $this->unitKey,
            'ordinal' => $this->ordinal,
            'content' => $this->content,
            'heading_path' => $this->headingPath,
            'references' => $this->references,
            'metadata' => $this->metadata,
        ];
    }
}