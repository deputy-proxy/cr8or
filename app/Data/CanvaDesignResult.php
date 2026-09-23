<?php

namespace App\Data;

final readonly class CanvaDesignResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $externalId,
        public ?string $externalUrl,
        public array $metadata = [],
    ) {}
}
