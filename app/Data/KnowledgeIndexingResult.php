<?php

namespace App\Data;

final readonly class KnowledgeIndexingResult
{
    public function __construct(
        public string $correlationId,
        public int $indexedUnits,
        public int $failedUnits = 0,
    ) {}
}