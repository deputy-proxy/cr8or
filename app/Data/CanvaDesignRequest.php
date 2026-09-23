<?php

namespace App\Data;

final readonly class CanvaDesignRequest
{
    /** @param array<string, mixed> $designType */
    public function __construct(
        public string $title,
        public array $designType,
        public ?string $assetId = null,
    ) {}
}
